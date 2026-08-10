<?php

declare(strict_types=1);

namespace Drupal\elan_bridge\Event;

/**
 * Signs the exact canonical event bytes sent to Bridge.
 */
final class EventSigner {

  /**
   * Returns the shared first-party HMAC header value.
   */
  public static function sign(string $secret, string $timestamp, string $payload): string {
    return 'sha256=' . hash_hmac('sha256', $timestamp . $payload, $secret);
  }

}
