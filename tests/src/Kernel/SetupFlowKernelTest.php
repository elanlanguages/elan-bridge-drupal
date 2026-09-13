<?php

declare(strict_types=1);

namespace Drupal\Tests\elan_bridge\Kernel;

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
