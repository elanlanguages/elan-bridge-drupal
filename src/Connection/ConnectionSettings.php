<?php

declare(strict_types=1);

namespace Drupal\elan_bridge\Connection;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * Reads non-secret connection metadata and resolves secrets through Key.
 */
final class ConnectionSettings {

  public const CONFIG_NAME = 'elan_bridge.settings';

  /**
   * Constructs the settings reader.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly KeyRepositoryInterface $keyRepository,
  ) {}

  /**
   * Returns the ELAN application base URL without a trailing slash.
   */
  public function bridgeUrl(): string {
    return rtrim((string) $this->configFactory->get(self::CONFIG_NAME)->get('bridge_url'), '/');
  }

  /**
   * Returns the Bridge connection identifier for this Drupal site.
   */
  public function connectionId(): string {
    return trim((string) $this->configFactory->get(self::CONFIG_NAME)->get('connection_id'));
  }

  /**
   * Returns the credential Bridge presents to Drupal snapshot endpoints.
   */
  public function bearerSecret(): string {
    return $this->keyValue('bearer_key_id');
  }

  /**
   * Returns the secret Drupal uses to sign outbound events.
   */
  public function webhookSecret(): string {
    return $this->keyValue('webhook_key_id');
  }

  /**
   * Returns whether every required connection value is available.
   */
  public function isConfigured(): bool {
    return $this->bridgeUrl() !== ''
      && $this->connectionId() !== ''
      && $this->bearerSecret() !== ''
      && $this->webhookSecret() !== '';
  }

  /**
   * Pins pending events to the connection and Key references that created them.
   */
  public function deliveryFingerprint(): string {
    $config = $this->configFactory->get(self::CONFIG_NAME);
    return hash('sha256', implode("\0", [
      $this->bridgeUrl(),
      $this->connectionId(),
      trim((string) $config->get('bearer_key_id')),
      trim((string) $config->get('webhook_key_id')),
    ]));
  }

  /**
   * Resolves one referenced Key without exporting its value to configuration.
   */
  private function keyValue(string $setting): string {
    $key_id = trim((string) $this->configFactory->get(self::CONFIG_NAME)->get($setting));
    if ($key_id === '') {
      return '';
    }
    $key = $this->keyRepository->getKey($key_id);
    return $key === NULL ? '' : (string) $key->getKeyValue();
  }

}
