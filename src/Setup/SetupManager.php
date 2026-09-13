<?php

declare(strict_types=1);

namespace Drupal\elan_bridge\Setup;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\elan_bridge\Connection\ConnectionSettings;
use GuzzleHttp\ClientInterface;

/**
 * Coordinates a site-bound, administrator-approved connection to hosted demo.
 */
final class SetupManager {

  public const DEMO = 'https://demo.elanlanguages.ai';

  /**
   * Constructs the setup coordinator.
   */
  public function __construct(
    private readonly SecretStore $secrets,
    private readonly ClientInterface $http,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entities,
    private readonly LanguageManagerInterface $languages,
    private readonly AccountProxyInterface $account,
    private readonly TimeInterface $time,
    private readonly StateInterface $state,
    private readonly ConnectionSettings $connection,
    private readonly LockBackendInterface $lock,
    private readonly Connection $database,
  ) {}

  /**
   * Normalizes a public site base URL, retaining any Drupal subdirectory.
   */
  public static function normalizeUrl(string $url): string {
    $parts = parse_url(trim($url));
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) {
      throw new \InvalidArgumentException('Enter the public HTTPS address of this Drupal site.');
    }
    return rtrim(trim($url), '/');
  }

  /**
   * Starts setup without replacing the site's working connection.
   */
  public function start(string $site_url, string $admin_url): string {
    if (!$this->lock->acquire('elan_bridge.setup', 60)) {
      throw new \RuntimeException('Another administrator is connecting this site. Retry shortly.');
    }
    try {
      $site_url = self::normalizeUrl($site_url);
      $prior = $this->secrets->get('pending');
      if (!empty($prior['handoff_id']) && $prior['expires'] >= $this->time->getCurrentTime()) {
        if ($prior['uid'] !== (int) $this->account->id() || $prior['site_url'] !== $site_url) {
          throw new \RuntimeException('Another setup is in progress. Complete it or wait ten minutes before starting again.');
        }
        return self::DEMO . '/connect/drupal?handoff=' . $prior['handoff_id'];
      }
      $source = $this->languages->getDefaultLanguage()->getId();
      $targets = array_values(array_diff(array_keys($this->languages->getLanguages()), [$source]));
      if ($targets === []) {
        throw new \RuntimeException('Enable at least one target language in Drupal before connecting.');
      }
      $installation = $this->state->get('elan_bridge.installation_id') ?: bin2hex(random_bytes(32));
      $this->state->set('elan_bridge.installation_id', $installation);
      $previous = $this->connection->connectionId() ?: $this->state->get('elan_bridge.previous_connection_id', '');
      $pending = [
        'site_url' => $site_url,
        'admin_url' => self::normalizeUrl($admin_url),
        'label' => (string) $this->configFactory->get('system.site')->get('name'),
        'installation_id' => $installation,
        'state' => bin2hex(random_bytes(32)),
        'verifier' => bin2hex(random_bytes(32)),
        'bearer' => $this->connection->bearerSecret() ?: ($this->secrets->get('elan_bridge_bearer')['value'] ?? $prior['bearer'] ?? bin2hex(random_bytes(32))),
        'webhook_secret' => $this->connection->webhookSecret() ?: ($this->secrets->get('elan_bridge_webhook')['value'] ?? $prior['webhook_secret'] ?? bin2hex(random_bytes(32))),
        'previous_connection_id' => $previous,
        'previous_project_id' => $this->state->get('elan_bridge.setup', [])['project_id'] ?? '',
        'source_language' => $source,
        'target_languages' => $targets,
        'uid' => (int) $this->account->id(),
        'expires' => $this->time->getCurrentTime() + 600,
      ];
      $this->secrets->set('pending', $pending);
      $payload = $pending;
      $payload['verifier_hash'] = hash('sha256', $pending['verifier']);
      unset($payload['verifier'], $payload['uid'], $payload['expires']);
      $result = $this->request('initiate', $payload);
      if (!preg_match('/^[a-f0-9]{64}$/', $result['handoff_id'] ?? '')) {
        throw new \RuntimeException('ELAN returned an invalid setup response.');
      }
      $pending['handoff_id'] = $result['handoff_id'];
      $this->secrets->set('pending', $pending);
      return self::DEMO . '/connect/drupal?handoff=' . $result['handoff_id'];
    }
    finally {
      $this->lock->release('elan_bridge.setup');
    }
  }

  /**
   * Proves callback ownership without exposing content or active credentials.
   */
  public function probe(string $authorization, array $body): array {
    $pending = $this->secrets->get('pending');
    if (empty($pending) || $pending['expires'] < $this->time->getCurrentTime() || !hash_equals('Bearer ' . $pending['bearer'], $authorization) || !hash_equals($pending['state'], (string) ($body['state'] ?? ''))) {
      throw new \RuntimeException('Invalid or expired setup proof.');
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $body['nonce'] ?? '')) {
      throw new \RuntimeException('Invalid setup challenge.');
    }
    return [
      'installation_id' => $pending['installation_id'],
      'site_url' => $pending['site_url'],
      'proof' => hash_hmac('sha256', $pending['state'] . ':' . $body['nonce'], $pending['webhook_secret']),
    ];
  }

  /**
   * Claims the approved result using a verifier that never enters the browser.
   */
  public function complete(string $handoff, string $state): void {
    if (!$this->lock->acquire('elan_bridge.setup', 60)) {
      throw new \RuntimeException('Setup is already finishing. Retry shortly.');
    }
    try {
      $pending = $this->secrets->get('pending');
      if (!$pending || $pending['expires'] < $this->time->getCurrentTime() || $pending['uid'] !== (int) $this->account->id() || !hash_equals($pending['state'], $state) || !hash_equals($pending['handoff_id'] ?? '', $handoff)) {
        throw new \RuntimeException('This setup does not match your Drupal session or has expired. Start again.');
      }
      $result = $this->request('claim', ['handoff_id' => $handoff, 'verifier' => $pending['verifier']]);
      $this->apply($result, $pending);
      $this->secrets->delete('pending');
    }
    finally {
      $this->lock->release('elan_bridge.setup');
    }
  }

  /**
   * Atomically configures Key references and the canonical TMGMT provider.
   */
  public function apply(array $result, array $pending): void {
    if (!is_int($result['binding_id'] ?? NULL) || $result['binding_id'] < 1 || !preg_match('/^[a-f0-9-]{36}$/', $result['connection_id'] ?? '') || empty($result['project_id']) || $result['source_language'] !== $pending['source_language']) {
      throw new \RuntimeException('ELAN returned an incomplete project binding.');
    }
    $bridge_url = self::normalizeUrl($result['bridge_url']);
    $targets = $result['target_languages'] ?? [];
    if (!$targets || array_diff($targets, $pending['target_languages'])) {
      throw new \RuntimeException('ELAN returned languages that are not enabled on this site.');
    }
    $storage = $this->entities->getStorage('tmgmt_translator');
    $provider = $storage->load('elan_bridge');
    if ($provider && $provider->getPluginId() !== 'elan_bridge') {
      throw new \RuntimeException('The provider ID elan_bridge belongs to another plugin. Resolve this before connecting.');
    }
    // Preserve other providers and their historical jobs.
    $others = $storage->loadByProperties(['plugin' => 'elan_bridge']);
    if (count($others) > ($provider ? 1 : 0)) {
      throw new \RuntimeException('Consolidate existing ELAN providers before using automatic setup.');
    }
    $transaction = $this->database->startTransaction();
    try {
      $settings = $this->configFactory->getEditable(ConnectionSettings::CONFIG_NAME);
      $key_ids = [
        'bearer' => $settings->get('bearer_key_id') ?: 'elan_bridge_bearer',
        'webhook_secret' => $settings->get('webhook_key_id') ?: 'elan_bridge_webhook',
      ];
      foreach ($key_ids as $type => $id) {
        $keys = $this->entities->getStorage('key');
        $key = $keys->load($id);
        if ($key) {
          // Preserve Key references used by immutable pending outbox events.
          if (!hash_equals((string) $key->getKeyValue(), $pending[$type])) {
            throw new \RuntimeException('The local credential changed during setup. Restart the connection.');
          }
          continue;
        }
        $this->secrets->set($id, ['value' => $pending[$type]]);
        $keys->create([
          'id' => $id,
          'label' => $type === 'bearer' ? 'ELAN bearer credential' : 'ELAN signing secret',
          'key_type' => 'authentication',
          'key_provider' => 'elan_bridge',
          'key_provider_settings' => [],
          'key_input' => 'none',
        ])->save();
      }
      $settings->set('bridge_url', $bridge_url)->set('connection_id', $result['connection_id'])
        ->set('bearer_key_id', $key_ids['bearer'])->set('webhook_key_id', $key_ids['webhook_secret'])->save();
      $provider = $provider ?: $storage->create([
        'name' => 'elan_bridge',
        'label' => 'ELAN AI Bridge',
        'plugin' => 'elan_bridge',
      ]);
      $mapping = array_combine(array_merge([$pending['source_language']], $targets), array_merge([$pending['source_language']], $targets));
      $provider->set('settings', ['binding_id' => $result['binding_id']])->set('auto_accept', FALSE)
        ->set('remote_languages_mappings', $mapping)->save();
      $this->state->set('elan_bridge.setup', [
        'site_url' => $pending['site_url'],
        'project_id' => $result['project_id'],
        'project_name' => $result['project_name'] ?? '',
        'organization' => $result['organization'] ?? '',
        'connected_at' => $this->time->getCurrentTime(),
      ]);
      $this->state->set('elan_bridge.previous_connection_id', $result['connection_id']);
    }
    catch (\Throwable $error) {
      $transaction->rollBack();
      throw $error;
    }
  }

  /**
   * Revokes the hosted connection while retaining historical jobs and results.
   */
  public function disconnect(): void {
    $active = $this->database->select('elan_bridge_snapshot', 's')->condition('status', 'pending')->countQuery()->execute()->fetchField();
    if ($active) {
      throw new \RuntimeException('Wait for pending translations or cancel them in TMGMT before disconnecting.');
    }
    $connection = $this->connection->connectionId();
    if ($connection === '') {
      return;
    }
    $timestamp = $this->time->getCurrentTime();
    $this->request('disconnect', [
      'connection_id' => $connection,
      'timestamp' => $timestamp,
      'proof' => hash_hmac('sha256', "disconnect:$connection:$timestamp", $this->connection->webhookSecret()),
    ]);
    $this->state->set('elan_bridge.previous_connection_id', $connection);
    $this->configFactory->getEditable(ConnectionSettings::CONFIG_NAME)->set('connection_id', '')->save();
    $status = $this->state->get('elan_bridge.setup', []);
    $status['connected_at'] = NULL;
    $this->state->set('elan_bridge.setup', $status);
    $this->secrets->delete('pending');
  }

  /**
   * Calls only the fixed hosted demo setup endpoint, without redirects.
   */
  private function request(string $action, array $body): array {
    try {
      $response = $this->http->request('POST', self::DEMO . '/api/connectors/drupal/' . $action, [
        'json' => $body,
        'timeout' => 45,
        'connect_timeout' => 10,
        'allow_redirects' => FALSE,
        'http_errors' => FALSE,
      ]);
    }
    catch (\Throwable $error) {
      throw new \RuntimeException('Cannot reach ELAN demo. Check outbound HTTPS access and retry.', 0, $error);
    }
    if ($response->getStatusCode() !== 200) {
      throw new \RuntimeException('ELAN could not finish this setup step. Retry, or restart setup if it expired.');
    }
    return json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

}
