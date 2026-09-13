<?php

declare(strict_types=1);

namespace Drupal\elan_bridge\Plugin\KeyProvider;

use Drupal\key\KeyInterface;
use Drupal\key\Plugin\KeyProviderBase;

/**
 * Resolves credentials established by the ELAN setup wizard.
 *
 * @KeyProvider(
 *   id = "elan_bridge",
 *   label = @Translation("ELAN connection"),
 *   description = @Translation("Encrypted site-local credentials managed by ELAN setup."),
 *   key_value = {"accepted" = FALSE, "required" = FALSE}
 * )
 */
final class ElanBridgeKeyProvider extends KeyProviderBase {

  /**
   * {@inheritdoc}
   */
  public function getKeyValue(KeyInterface $key) {
    return \Drupal::service('elan_bridge.secret_store')->get($key->id())['value'] ?? NULL;
  }

}
