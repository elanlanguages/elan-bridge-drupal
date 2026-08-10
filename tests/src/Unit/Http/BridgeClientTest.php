<?php

declare(strict_types=1);

namespace Drupal\Tests\elan_bridge\Unit\Http;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\elan_bridge\Connection\ConnectionSettings;
use Drupal\elan_bridge\Event\EventSigner;
use Drupal\elan_bridge\Http\BridgeClient;
use Drupal\elan_bridge\Http\DeliveryResult;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests the signed Drupal-to-Bridge HTTP boundary.
 */
final class BridgeClientTest extends TestCase {

  /**
   * Tests the direct API route and signature over exact payload bytes.
   */
  public function testDeliversToDirectDrupalWebhookRoute(): void {
    $settings = $this->connectionSettings();
    $payload = '{"event_id":"event-1"}';
    $client = $this->createMock(ClientInterface::class);
    $client->expects(self::once())
      ->method('request')
      ->with(
        'POST',
        'https://bridge.example/api/connectors/drupal/webhook/connection%2F1',
        self::callback(function (array $options) use ($payload): bool {
          self::assertSame($payload, $options['body']);
          self::assertFalse($options['allow_redirects']);
          $timestamp = $options['headers']['X-ELAN-Timestamp'];
          self::assertSame(
            EventSigner::sign('webhook-secret', $timestamp, $payload),
            $options['headers']['X-ELAN-Signature'],
          );
          return TRUE;
        }),
      )
      ->willReturn(new Response(202));

    $result = (new BridgeClient($client, $settings))->deliver(
      'event-1',
      $payload,
      $settings->deliveryFingerprint(),
    );

    self::assertTrue($result->delivered());
  }

  /**
   * Tests that an event cannot be rerouted after the pairing changes.
   */
  public function testRejectsChangedConnection(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects(self::never())->method('request');

    $result = (new BridgeClient($client, $this->connectionSettings()))->deliver(
      'event-1',
      '{}',
      str_repeat('0', 64),
    );

    self::assertSame(DeliveryResult::PERMANENT, $result->outcome);
  }

  /**
   * Tests HTTP status classification.
   */
  public function testClassifiesHttpStatus(): void {
    foreach (self::statusProvider() as $case => [$status, $expected]) {
      $settings = $this->connectionSettings();
      $client = $this->createMock(ClientInterface::class);
      $client->method('request')->willReturn(new Response($status));

      $result = (new BridgeClient($client, $settings))->deliver(
        'event-1',
        '{}',
        $settings->deliveryFingerprint(),
      );

      self::assertSame($expected, $result->outcome, $case);
    }
  }

  /**
   * Provides representative transport status classes.
   *
   * @return array<string, array{int, string}>
   *   Status and expected outcome pairs.
   */
  public static function statusProvider(): array {
    return [
      'accepted' => [202, DeliveryResult::DELIVERED],
      'timeout' => [408, DeliveryResult::RETRY],
      'too early' => [425, DeliveryResult::RETRY],
      'rate limited' => [429, DeliveryResult::RETRY],
      'server failure' => [503, DeliveryResult::RETRY],
      'contract failure' => [422, DeliveryResult::PERMANENT],
    ];
  }

  /**
   * Builds configured settings with independent bearer and HMAC Keys.
   */
  private function connectionSettings(): ConnectionSettings {
    $values = [
      'bridge_url' => 'https://bridge.example/api',
      'connection_id' => 'connection/1',
      'bearer_key_id' => 'bearer-key',
      'webhook_key_id' => 'webhook-key',
    ];
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn(string $key): mixed => $values[$key] ?? NULL,
    );
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturn($config);

    $bearer_key = $this->createMock(KeyInterface::class);
    $bearer_key->method('getKeyValue')->willReturn('bearer-secret');
    $webhook_key = $this->createMock(KeyInterface::class);
    $webhook_key->method('getKeyValue')->willReturn('webhook-secret');
    $keys = $this->createMock(KeyRepositoryInterface::class);
    $keys->method('getKey')->willReturnMap([
      ['bearer-key', $bearer_key],
      ['webhook-key', $webhook_key],
    ]);

    return new ConnectionSettings($config_factory, $keys);
  }

}
