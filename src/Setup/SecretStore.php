<?php

declare(strict_types=1);

namespace Drupal\elan_bridge\Setup;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Site\Settings;

/**
 * Keeps setup credentials out of exported configuration and public files.
 */
final class SecretStore {

  /**
   * Constructs the encrypted site-local store.
   */
  public function __construct(private readonly KeyValueFactoryInterface $factory) {}

  /**
   * Stores an encrypted value using the site's settings.php hash salt.
   */
  public function set(string $id, array $value): void {
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt(json_encode($value, JSON_THROW_ON_ERROR), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag, $id);
    if ($cipher === FALSE) {
      throw new \RuntimeException('Could not protect the ELAN connection credentials.');
    }
    $this->factory->get('elan_bridge.secrets')->set($id, base64_encode($iv . $tag . $cipher));
  }

  /**
   * Reads a protected value, failing closed if its encryption key changed.
   */
  public function get(string $id): array {
    $encoded = $this->factory->get('elan_bridge.secrets')->get($id);
    if ($encoded === NULL) {
      return [];
    }
    $raw = base64_decode($encoded, TRUE);
    if ($raw === FALSE || strlen($raw) < 29) {
      throw new \RuntimeException('Invalid ELAN credential storage. Restore the site backup or reconnect.');
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $id);
    if ($plain === FALSE) {
      throw new \RuntimeException('Cannot unlock ELAN credentials. Restore the original site hash salt.');
    }
    return json_decode($plain, TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Removes a pending setup or credential value.
   */
  public function delete(string $id): void {
    $this->factory->get('elan_bridge.secrets')->delete($id);
  }

  /**
   * Derives a purpose-specific encryption key.
   */
  private function key(): string {
    return hash_hkdf('sha256', Settings::getHashSalt(), 32, 'elan-bridge-credentials-v1');
  }

}
