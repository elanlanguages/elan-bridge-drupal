<?php

declare(strict_types=1);

namespace Drupal\Tests\elan_bridge\Unit\Event;

use Drupal\elan_bridge\Event\EventSigner;
use PHPUnit\Framework\TestCase;

/**
 * Tests the shared first-party CMS signing contract.
 */
final class EventSignerTest extends TestCase {

  /**
   * Tests that the timestamp is concatenated directly with exact JSON bytes.
   */
  public function testKnownVector(): void {
    self::assertSame(
      'sha256=f3a15680551495e7d27302c4d19e4cbedc650f19fa5b40373c00cbea52a64406',
      EventSigner::sign('secret', '1700000000', '{"a":1}'),
    );
  }

  /**
   * Tests that even insignificant JSON byte changes alter the signature.
   */
  public function testSignsExactPayloadBytes(): void {
    self::assertNotSame(
      EventSigner::sign('secret', '1700000000', '{"a":1}'),
      EventSigner::sign('secret', '1700000000', '{ "a": 1 }'),
    );
  }

}
