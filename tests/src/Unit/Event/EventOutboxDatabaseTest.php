<?php

declare(strict_types=1);

namespace Drupal\Tests\elan_bridge\Unit\Event;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\elan_bridge\Event\EventOutbox;
use Drupal\sqlite\Driver\Database\sqlite\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests outbox transitions against Drupal's real database query builders.
 */
final class EventOutboxDatabaseTest extends TestCase {

  /**
   * Outbox under test.
   */
  private EventOutbox $outbox;

  /**
   * Mutable deterministic time service.
   */
  private TimeInterface $time;

  /**
   * Queue backend mock.
   */
  private QueueInterface&MockObject $queue;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 4) . '/elan_bridge.install';

    $options = [
      'database' => ':memory:',
      'driver' => 'sqlite',
      'namespace' => 'Drupal\\sqlite\\Driver\\Database\\sqlite',
      'prefix' => '',
    ];
    $database = new Connection(Connection::open($options), $options);
    foreach (elan_bridge_schema() as $table => $schema) {
      $database->schema()->createTable($table, $schema);
    }

    $this->time = new class() implements TimeInterface {

      /**
       * Current test timestamp.
       */
      public int $now = 1_000;

      /**
       * {@inheritdoc}
       */
      public function getRequestTime(): int {
        return $this->now;
      }

      /**
       * {@inheritdoc}
       */
      public function getRequestMicroTime(): float {
        return (float) $this->now;
      }

      /**
       * {@inheritdoc}
       */
      public function getCurrentTime(): int {
        return $this->now;
      }

      /**
       * {@inheritdoc}
       */
      public function getCurrentMicroTime(): float {
        return (float) $this->now;
      }

    };
    $this->queue = $this->createMock(QueueInterface::class);
    $queue_factory = $this->createMock(QueueFactory::class);
    $queue_factory->method('get')->willReturn($this->queue);
    $this->outbox = new EventOutbox($database, $queue_factory, $this->time);
  }

  /**
   * Tests that cancellation cannot be overwritten by a worker completion.
   */
  public function testCancellationWinsOverDelivery(): void {
    $id = $this->persistEvent();
    self::assertSame('processing', $this->outbox->beginAttempt($id)['status']);

    $this->outbox->cancelBySnapshots(['snapshot-1']);

    self::assertFalse($this->outbox->markDelivered($id));
    self::assertSame('cancelled', $this->outbox->load($id)['status']);
  }

  /**
   * Tests that retry availability is durable and atomically claimed.
   */
  public function testRetryDelayAndClaim(): void {
    $id = $this->persistEvent();
    self::assertNotNull($this->outbox->beginAttempt($id));
    self::assertTrue($this->outbox->markRetry($id, 'try again', 60));

    $this->time->now = 1_059;
    self::assertNull($this->outbox->beginAttempt($id));
    $this->time->now = 1_060;
    self::assertSame('processing', $this->outbox->beginAttempt($id)['status']);
    self::assertTrue($this->outbox->markDelivered($id));
    self::assertSame('delivered', $this->outbox->load($id)['status']);
  }

  /**
   * Tests that cron recreates a wake-up for a stranded pending row.
   */
  public function testRecoversMissingQueueWakeup(): void {
    $id = $this->persistEvent();
    $this->queue->expects(self::once())
      ->method('createItem')
      ->with(['outbox_id' => $id])
      ->willReturn('queue-item-1');

    $this->time->now = 1_301;

    self::assertSame(1, $this->outbox->recover());
  }

  /**
   * Tests explicit administrator repair of a terminal event.
   */
  public function testRetriesDeadEvent(): void {
    $id = $this->persistEvent();
    self::assertNotNull($this->outbox->beginAttempt($id));
    self::assertTrue($this->outbox->markDead($id, 'repairable config'));
    $this->queue->expects(self::once())
      ->method('createItem')
      ->with(['outbox_id' => $id])
      ->willReturn('queue-item-1');

    self::assertSame(1, $this->outbox->retryDead());
    self::assertSame('failed', $this->outbox->load($id)['status']);
  }

  /**
   * Persists one valid strict-event stand-in.
   */
  private function persistEvent(): int {
    return $this->outbox->persist(
      ['event_id' => 'event-1'],
      'snapshot-1',
      str_repeat('a', 64),
    );
  }

}
