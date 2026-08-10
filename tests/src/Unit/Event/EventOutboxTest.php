<?php

declare(strict_types=1);

namespace Drupal\Tests\elan_bridge\Unit\Event;

use Drupal\elan_bridge\Event\EventOutbox;
use PHPUnit\Framework\TestCase;

/**
 * Tests provider-independent outbox policies.
 */
final class EventOutboxTest extends TestCase {

  /**
   * Tests capped exponential delay and bounded jitter.
   */
  public function testRetryDelay(): void {
    self::assertSame(60, EventOutbox::retryDelay(1));
    self::assertSame(125, EventOutbox::retryDelay(2, 5));
    self::assertSame(3600, EventOutbox::retryDelay(20, 900));
    self::assertSame(60, EventOutbox::retryDelay(1, -10));
  }

}
