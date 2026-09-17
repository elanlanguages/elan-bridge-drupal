<?php

declare(strict_types=1);

namespace Drupal\Tests\elan_bridge\Kernel;

use Drupal\Core\State\State;
use Drupal\elan_bridge\Setup\SecretStore;
use Drupal\Component\Serialization\PhpSerialize;
use Drupal\Core\KeyValueStore\KeyValueDatabaseFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;
use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\tmgmt\Entity\Translator;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises the ELAN provider through Drupal and real TMGMT entities.
 *
 * PHPUnit 9 ignores the attribute while Drupal 10's KernelTestBase enables
 * isolation itself; Drupal 11.3+ requires the explicit PHPUnit 11 metadata.
 *
 * @group elan_bridge
 */
#[RunTestsInSeparateProcesses]
final class SetupFlowKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'language',
    'options',
    'tmgmt',
    'tmgmt_test',
    'key',
    'elan_bridge',
  ];

  /**
   * Working directory used before entering the synthetic Drupal root.
   */
  private string $originalWorkingDirectory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // PHPUnit 9 resolves the test suite from the repository, while Drupal 10's
    // legacy SQLite driver resolves its module metadata from the Drupal root.
    $original_working_directory = getcwd();
    if ($original_working_directory === FALSE) {
      throw new \RuntimeException('Could not determine the PHPUnit working directory.');
    }
    $this->originalWorkingDirectory = $original_working_directory;
    $drupal_root = dirname(__DIR__, 3) . '/vendor/drupal';
    if (!chdir($drupal_root)) {
      throw new \RuntimeException('Could not enter the synthetic Drupal test root.');
    }
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('tmgmt_job');
    $this->installEntitySchema('tmgmt_job_item');
    $this->installEntitySchema('tmgmt_message');
    $this->installEntitySchema('tmgmt_remote');
    $this->installSchema('elan_bridge', [
      'elan_bridge_snapshot',
      'elan_bridge_event_outbox',
    ]);
    $this->installConfig(['elan_bridge']);

    ConfigurableLanguage::createFromLangcode('de')->save();
    $this->createKey('elan_bridge_bearer', 'kernel-bearer-secret');
    $this->createKey('elan_bridge_webhook', 'kernel-webhook-secret');
    $this->config('elan_bridge.settings')
      ->set('bridge_url', 'https://bridge.example.test/api')
      ->set('connection_id', 'drupal-kernel-site')
      ->set('bearer_key_id', 'elan_bridge_bearer')
      ->set('webhook_key_id', 'elan_bridge_webhook')
      ->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    try {
      parent::tearDown();
    }
    finally {
      if (!chdir($this->originalWorkingDirectory)) {
        throw new \RuntimeException('Could not restore the PHPUnit working directory.');
      }
    }
  }

  /**
   * Installs from empty settings and checks credentials, routing and replay.
   */
  public function testSelfServiceSetup(): void {
    $this->config('elan_bridge.settings')->set('connection_id', '')->set('bearer_key_id', '')->set('webhook_key_id', '')->save();
    Key::load('elan_bridge_bearer')->delete();
    Key::load('elan_bridge_webhook')->delete();
    $handoff = str_repeat('a', 64);
    $history = [];
    $mock = new MockHandler([
      new Response(200, [], json_encode(['handoff_id' => $handoff])),
      new Response(200, [], json_encode([
        'connection_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        'binding_id' => 42,
        'project_id' => 'demo-project',
        'bridge_url' => 'https://tms-llm-bridge.fly.dev',
        'project_name' => 'Drupal translations',
        'organization' => 'ELAN',
        'source_language' => 'en',
        'target_languages' => ['de'],
      ])),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    $manager = $this->container->get('elan_bridge.setup_manager');
    $store = $this->container->get('elan_bridge.secret_store');
    $url = $manager->start('https://cms.example.org/subdir/', 'https://cms.example.org/subdir');
    self::assertSame('https://demo.elanlanguages.ai/connect/drupal?handoff=' . $handoff, $url);
    self::assertSame($url, $manager->start('https://cms.example.org/subdir', 'https://cms.example.org/subdir'));
    self::assertCount(1, $history);
    $pending = $store->get('pending');
    self::assertNotSame($pending['bearer'], $pending['webhook_secret']);
    self::assertStringNotContainsString($pending['verifier'], (string) $history[0]['request']->getBody());
    self::assertSame('', $this->config('elan_bridge.settings')->get('connection_id'));
    $nonce = str_repeat('b', 64);
    $proof = $manager->probe('Bearer ' . $pending['bearer'], ['state' => $pending['state'], 'nonce' => $nonce]);
    self::assertSame(hash_hmac('sha256', $pending['state'] . ':' . $nonce, $pending['webhook_secret']), $proof['proof']);
    try {
      $manager->probe('Bearer invalid', ['state' => $pending['state'], 'nonce' => $nonce]);
      self::fail('Invalid bearer must be rejected.');
    }
    catch (\RuntimeException $error) {
      self::assertStringContainsString('Invalid', $error->getMessage());
    }
    try {
      $manager->complete($handoff, 'attacker-state');
      self::fail('Invalid browser state must be rejected.');
    }
    catch (\RuntimeException $error) {
      self::assertStringContainsString('does not match', $error->getMessage());
    }
    self::assertCount(1, $history);
    $other_session = $pending;
    $other_session['uid']++;
    $store->set('pending', $other_session);
    try {
      $manager->complete($handoff, $pending['state']);
      self::fail('Another Drupal administrator must not claim this setup.');
    }
    catch (\RuntimeException $error) {
      self::assertStringContainsString('does not match', $error->getMessage());
    }
    $expired = $pending;
    $expired['expires'] = 1;
    $store->set('pending', $expired);
    try {
      $manager->complete($handoff, $pending['state']);
      self::fail('An expired setup must not be claimed.');
    }
    catch (\RuntimeException $error) {
      self::assertStringContainsString('expired', $error->getMessage());
    }
    $store->set('pending', $pending);
    self::assertCount(1, $history);
    $manager->complete($handoff, $pending['state']);
    self::assertSame([], $store->get('pending'));
    $provider = Translator::load('elan_bridge');
    self::assertNotNull($provider);
    self::assertSame(42, $provider->getSetting('binding_id'));
    self::assertFalse($provider->get('auto_accept'));
    self::assertSame(['en' => 'en', 'de' => 'de'], $provider->get('remote_languages_mappings'));
    self::assertCount(1, Translator::loadMultiple());
    $key = Key::load('elan_bridge_bearer');
    self::assertSame($pending['bearer'], $key->getKeyValue());
    self::assertStringNotContainsString($pending['bearer'], json_encode($key->toArray()));
    self::assertStringNotContainsString($pending['bearer'], $this->container->get('keyvalue')->get('elan_bridge.secrets')->get('elan_bridge_bearer'));
    $this->expectException(\RuntimeException::class);
    $manager->complete($handoff, $pending['state']);
  }

  /**
   * Keeps manually configured Key references and outbox identity on reconnect.
   */
  public function testReconnectPreservesExistingKeyReferences(): void {
    $connection = $this->container->get('elan_bridge.connection_settings');
    $fingerprint = $connection->deliveryFingerprint();
    $this->container->get('elan_bridge.setup_manager')->apply([
      'connection_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
      'binding_id' => 42,
      'project_id' => 'demo-project',
      'bridge_url' => 'https://bridge.example.test/api',
      'source_language' => 'en',
      'target_languages' => ['de'],
    ], [
      'site_url' => 'https://cms.example.org',
      'source_language' => 'en',
      'target_languages' => ['de'],
      'bearer' => 'kernel-bearer-secret',
      'webhook_secret' => 'kernel-webhook-secret',
    ]);
    self::assertSame('config', Key::load('elan_bridge_bearer')->get('key_provider'));
    self::assertSame('kernel-bearer-secret', $connection->bearerSecret());
    self::assertSame('elan_bridge_bearer', $this->config('elan_bridge.settings')->get('bearer_key_id'));
    // Only the test's connection ID differs; reset it to check the other parts.
    $this->config('elan_bridge.settings')->set('connection_id', 'drupal-kernel-site')->save();
    self::assertSame($fingerprint, $connection->deliveryFingerprint());
  }

  /**
   * Rejects encrypted data moved to a different credential identifier.
   */
  public function testSecretStorageTampering(): void {
    $store = $this->container->get('elan_bridge.secret_store');
    $store->set('first', ['value' => 'secret']);
    $kv = $this->container->get('keyvalue')->get('elan_bridge.secrets');
    $kv->set('second', $kv->get('first'));
    $this->expectException(\RuntimeException::class);
    $store->get('second');
  }

  /**
   * Unreadable encrypted credentials make the provider unavailable, not fatal.
   */
  public function testUnreadableKeyFailsClosed(): void {
    $key = Key::load('elan_bridge_bearer');
    $key->set('key_provider', 'elan_bridge')->set('key_provider_settings', [])->save();
    $this->container->get('keyvalue')->get('elan_bridge.secrets')->set('elan_bridge_bearer', 'corrupt');
    self::assertNull($key->getKeyValue());
    self::assertFalse($this->container->get('elan_bridge.connection_settings')->isConfigured());
  }

  /**
   * Invalid hosted JSON shapes cannot change a working local connection.
   */
  public function testInvalidSetupResponses(): void {
    $mock = new MockHandler([new Response(200, [], 'null')]);
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create($mock)]));
    $manager = $this->container->get('elan_bridge.setup_manager');
    try {
      $manager->start('https://cms.example.org', 'https://cms.example.org');
      self::fail('Scalar JSON must be rejected.');
    }
    catch (\RuntimeException $error) {
      self::assertStringContainsString('invalid setup response', $error->getMessage());
    }
    foreach (['bridge_url' => [], 'target_languages' => 'de', 'connection_id' => [], 'source_language' => NULL] as $field => $value) {
      $result = $this->setupResult();
      $result[$field] = $value;
      try {
        $manager->apply($result, $this->setupPending());
        self::fail('Invalid binding must be rejected.');
      }
      catch (\RuntimeException $error) {
        self::assertStringStartsWith('ELAN returned', $error->getMessage());
      }
      self::assertSame('drupal-kernel-site', $this->config('elan_bridge.settings')->get('connection_id'));
      self::assertNull(Translator::load('elan_bridge'));
    }
  }

  /**
   * Rollback restores config, entities and state within the same request.
   */
  public function testApplyRollbackClearsCaches(): void {
    Key::load('elan_bridge_bearer')->delete();
    Key::load('elan_bridge_webhook')->delete();
    // Kernel tests default to memory key/value storage without transactions.
    // Exercise rollback against the database stores used by a real site.
    $factory = new KeyValueDatabaseFactory(new PhpSerialize(), $this->container->get('database'));
    $this->container->set('elan_bridge.secret_store', new SecretStore($factory));
    $state = new State($factory, $this->container->get('cache.bootstrap'), $this->container->get('lock'));
    $state->set('elan_bridge.setup', ['project_id' => 'original']);
    $failing_state = $this->createMock(StateInterface::class);
    $failing_state->method('set')->willReturnCallback(function ($key, $value) use ($state) {
      $state->set($key, $value);
      if ($key === 'elan_bridge.previous_connection_id') {
        throw new \RuntimeException('Injected failure after setup writes.');
      }
    });
    $failing_state->method('resetCache')->willReturnCallback(fn() => $state->resetCache());
    $this->container->set('state', $failing_state);
    try {
      $this->container->get('elan_bridge.setup_manager')->apply($this->setupResult(), $this->setupPending());
      self::fail('Injected failure must roll back.');
    }
    catch (\RuntimeException $error) {
      self::assertStringContainsString('Injected failure', $error->getMessage());
    }
    self::assertSame('drupal-kernel-site', $this->container->get('elan_bridge.connection_settings')->connectionId());
    self::assertNull(Translator::load('elan_bridge'));
    self::assertNull(Key::load('elan_bridge_bearer'));
    self::assertNull(Key::load('elan_bridge_webhook'));
    self::assertSame([], $this->container->get('elan_bridge.secret_store')->get('elan_bridge_bearer'));
    self::assertSame(['project_id' => 'original'], $state->get('elan_bridge.setup'));
    self::assertNull($state->get('elan_bridge.previous_connection_id'));
    // Verify a later request also cannot see rolled-back configuration.
    $this->container->get('config.factory')->reset();
    self::assertSame('drupal-kernel-site', $this->config('elan_bridge.settings')->get('connection_id'));
  }

  /**
   * A hosted outage revokes local access and leaves an explicit retry path.
   */
  public function testDisconnectDuringOutage(): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
      new Response(503),
      new Response(200, [], '{"disconnected":true}'),
    ]));
    $stack->push(Middleware::history($history));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    $manager = $this->container->get('elan_bridge.setup_manager');
    self::assertFalse($manager->disconnect());
    self::assertFalse($this->container->get('elan_bridge.connection_settings')->isConfigured());
    self::assertSame('drupal-kernel-site', $this->container->get('state')->get('elan_bridge.pending_disconnect'));
    $request = Request::create('/');
    $request->headers->set('Authorization', 'Bearer kernel-bearer-secret');
    try {
      $this->container->get('elan_bridge.snapshot_controller')->view($request, str_repeat('a', 64));
      self::fail('Retained credentials must not authorize a disconnected site.');
    }
    catch (HttpException $error) {
      self::assertSame(401, $error->getStatusCode());
    }
    try {
      $manager->start('https://cms.example.org', 'https://cms.example.org');
      self::fail('Outstanding hosted revocation must be reconciled first.');
    }
    catch (\RuntimeException $error) {
      self::assertStringContainsString('pending ELAN disconnect', $error->getMessage());
    }
    self::assertTrue($manager->disconnect());
    self::assertNull($this->container->get('state')->get('elan_bridge.pending_disconnect'));
    self::assertCount(2, $history);
    foreach ($history as $entry) {
      $body = json_decode((string) $entry['request']->getBody(), TRUE);
      self::assertSame('drupal-kernel-site', $body['connection_id']);
      self::assertSame(hash_hmac('sha256', 'disconnect:drupal-kernel-site:' . $body['timestamp'], 'kernel-webhook-secret'), $body['proof']);
    }
    self::assertSame('kernel-bearer-secret', Key::load('elan_bridge_bearer')->getKeyValue());
  }

  /**
   * Returns an approved hosted binding for failure and rollback tests.
   */
  private function setupResult(): array {
    return [
      'connection_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
      'binding_id' => 42,
      'project_id' => 'demo-project',
      'bridge_url' => 'https://bridge.example.test/api',
      'source_language' => 'en',
      'target_languages' => ['de'],
    ];
  }

  /**
   * Returns the matching site-bound setup request.
   */
  private function setupPending(): array {
    return [
      'site_url' => 'https://cms.example.org',
      'source_language' => 'en',
      'target_languages' => ['de'],
      'bearer' => 'kernel-bearer-secret',
      'webhook_secret' => 'kernel-webhook-secret',
    ];
  }

  /**
   * Creates test credentials through Key's configuration provider.
   */
  private function createKey(string $id, string $value): void {
    Key::create([
      'id' => $id,
      'label' => $id,
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => $value],
    ])->save();
  }

}
