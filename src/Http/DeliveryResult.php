<?php

declare(strict_types=1);

namespace Drupal\elan_bridge\Http;

/**
 * Classifies one outbound event-delivery attempt.
 */
final class DeliveryResult {

  public const DELIVERED = 'delivered';
  public const RETRY = 'retry';
  public const PERMANENT = 'permanent';

  /**
   * Constructs a delivery result.
   */
  public function __construct(
    public readonly string $outcome,
    public readonly string $error = '',
  ) {}

  /**
   * Returns whether Bridge durably accepted the event.
   */
  public function delivered(): bool {
    return $this->outcome === self::DELIVERED;
  }

  /**
   * Returns whether the same event identity should be retried.
   */
  public function retryable(): bool {
    return $this->outcome === self::RETRY;
  }

}
