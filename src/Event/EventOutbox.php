<?php

declare(strict_types=1);

namespace Drupal\elan_bridge\Event;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;

/**
 * Durable event source of truth backed by a Drupal Queue API wake-up.
 */
final class EventOutbox {

  public const QUEUE_ID = 'elan_bridge_delivery';

  private const TABLE = 'elan_bridge_event_outbox';

  private const PROCESSING_LEASE_SECONDS = 900;

  private const PENDING_RECOVERY_SECONDS = 300;

  /**
   * Constructs the outbox.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly QueueFactory $queueFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Persists an event before scheduling any delivery work.
   *
   * @param array<string, mixed> $event
   *   Strict canonical event payload.
   * @param string $snapshot_token
   *   Opaque snapshot handle associated with the event.
   * @param string $connection_fingerprint
   *   Immutable non-secret identity of the paired delivery route.
   */
  public function persist(
    array $event,
    string $snapshot_token,
    string $connection_fingerprint,
  ): int {
    $payload = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $now = $this->time->getCurrentTime();
    $id = (int) $this->database->insert(self::TABLE)
      ->fields([
        'event_id' => (string) $event['event_id'],
        'snapshot_token' => $snapshot_token,
        'connection_fingerprint' => $connection_fingerprint,
        'payload' => $payload,
        'status' => 'pending',
        'attempts' => 0,
        'available' => $now,
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();
    return $id;
  }

  /**
   * Publishes a lightweight wake-up after its outbox transaction commits.
   */
  public function schedule(int $id): bool {
    $queue_id = $this->queueFactory
      ->get(self::QUEUE_ID, TRUE)
      ->createItem(['outbox_id' => $id]);
    if ($queue_id === FALSE) {
      return FALSE;
    }
    $this->database->update(self::TABLE)
      ->fields(['updated' => $this->time->getCurrentTime()])
      ->condition('id', $id)
      ->condition('status', ['pending', 'failed'], 'IN')
      ->execute();
    return TRUE;
  }

  /**
   * Loads one outbox record.
   *
   * @return array<string, mixed>|null
   *   Outbox row, or NULL when missing.
   */
  public function load(int $id): ?array {
    $row = $this->database->select(self::TABLE, 'event')
      ->fields('event')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $row : NULL;
  }

  /**
   * Returns the durable outbox row associated with a snapshot.
   */
  public function idForSnapshot(string $snapshot_token): ?int {
    $id = $this->database->select(self::TABLE, 'event')
      ->fields('event', ['id'])
      ->condition('snapshot_token', $snapshot_token)
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $id === FALSE ? NULL : (int) $id;
  }

  /**
   * Records the start of one leased Queue API attempt.
   *
   * @return array<string, mixed>|null
   *   Fresh row, or NULL when the event is already terminal.
   */
  public function beginAttempt(int $id): ?array {
    $row = $this->load($id);
    if ($row === NULL) {
      return NULL;
    }
    $now = $this->time->getCurrentTime();
    $status = (string) $row['status'];
    if (in_array($status, ['pending', 'failed'], TRUE)) {
      if ((int) $row['available'] > $now) {
        return NULL;
      }
    }
    elseif ($status !== 'processing' || (int) $row['lease_until'] > $now) {
      return NULL;
    }

    $update = $this->database->update(self::TABLE)
      ->fields([
        'status' => 'processing',
        'attempts' => ((int) $row['attempts']) + 1,
        'lease_until' => $now + self::PROCESSING_LEASE_SECONDS,
        'updated' => $now,
      ])
      ->condition('id', $id)
      ->condition('status', $status);
    if ($status === 'processing') {
      $update->condition('lease_until', (int) $row['lease_until']);
    }
    else {
      $update->condition('available', $now, '<=');
    }
    if ((int) $update->execute() !== 1) {
      return NULL;
    }
    return $this->load($id);
  }

  /**
   * Marks a Bridge-accepted event terminally delivered.
   */
  public function markDelivered(int $id): bool {
    $now = $this->time->getCurrentTime();
    $updated = $this->database->update(self::TABLE)
      ->fields([
        'status' => 'delivered',
        'available' => 0,
        'lease_until' => NULL,
        'last_error' => NULL,
        'updated' => $now,
        'delivered' => $now,
      ])
      ->condition('id', $id)
      ->condition('status', 'processing')
      ->execute();
    return (int) $updated === 1;
  }

  /**
   * Records a retryable delivery failure.
   */
  public function markRetry(int $id, string $error, int $delay): bool {
    return $this->markFailure($id, 'failed', $error, $delay);
  }

  /**
   * Records a permanent delivery failure.
   */
  public function markDead(int $id, string $error): bool {
    return $this->markFailure($id, 'dead', $error);
  }

  /**
   * Cancels queued events for the supplied snapshot tokens.
   *
   * @param string[] $tokens
   *   Opaque snapshot tokens.
   */
  public function cancelBySnapshots(array $tokens): void {
    if ($tokens === []) {
      return;
    }
    $this->database->update(self::TABLE)
      ->fields([
        'status' => 'cancelled',
        'lease_until' => NULL,
        'updated' => $this->time->getCurrentTime(),
      ])
      ->condition('snapshot_token', $tokens, 'IN')
      ->condition('status', ['delivered', 'cancelled'], 'NOT IN')
      ->execute();
  }

  /**
   * Recreates wake-ups for rows that lost a queue item or a worker lease.
   */
  public function recover(int $limit = 100): int {
    $now = $this->time->getCurrentTime();
    $this->database->update(self::TABLE)
      ->fields([
        'status' => 'failed',
        'available' => $now,
        'lease_until' => NULL,
        'last_error' => 'A stale delivery lease was recovered.',
        'updated' => $now,
      ])
      ->condition('status', 'processing')
      ->condition('lease_until', $now, '<=')
      ->execute();

    $query = $this->database->select(self::TABLE, 'event');
    $pending = $query->andConditionGroup()
      ->condition('status', 'pending')
      ->condition('updated', $now - self::PENDING_RECOVERY_SECONDS, '<=');
    $retry = $query->andConditionGroup()
      ->condition('status', 'failed')
      ->condition('available', $now, '<=');
    $query->condition($query->orConditionGroup()->condition($pending)->condition($retry));
    $ids = $query->fields('event', ['id'])
      ->range(0, max(1, $limit))
      ->execute()
      ->fetchCol();

    $scheduled = 0;
    foreach ($ids as $id) {
      $scheduled += $this->schedule((int) $id) ? 1 : 0;
    }
    return $scheduled;
  }

  /**
   * Reopens permanently failed rows after an administrator repairs config.
   */
  public function retryDead(): int {
    $ids = $this->database->select(self::TABLE, 'event')
      ->fields('event', ['id'])
      ->condition('status', 'dead')
      ->execute()
      ->fetchCol();
    if ($ids === []) {
      return 0;
    }

    $now = $this->time->getCurrentTime();
    $this->database->update(self::TABLE)
      ->fields([
        'status' => 'failed',
        'available' => $now,
        'lease_until' => NULL,
        'last_error' => NULL,
        'updated' => $now,
      ])
      ->condition('id', $ids, 'IN')
      ->condition('status', 'dead')
      ->execute();
    $this->database->update('elan_bridge_snapshot')
      ->fields([
        'status' => 'pending',
        'updated' => $now,
      ])
      ->condition('status', 'failed')
      ->condition('token', $this->snapshotTokens($ids), 'IN')
      ->execute();

    $scheduled = 0;
    foreach ($ids as $id) {
      $scheduled += $this->schedule((int) $id) ? 1 : 0;
    }
    return $scheduled;
  }

  /**
   * Computes capped exponential backoff with bounded jitter.
   */
  public static function retryDelay(int $attempt, int $jitter = 0): int {
    $base = min(3600, 60 * (2 ** max(0, min(6, $attempt - 1))));
    return min(3600, $base + max(0, min(intdiv($base, 4), $jitter)));
  }

  /**
   * Persists one bounded failure message.
   */
  private function markFailure(
    int $id,
    string $status,
    string $error,
    int $delay = 0,
  ): bool {
    $updated = $this->database->update(self::TABLE)
      ->fields([
        'status' => $status,
        'available' => $status === 'failed'
          ? $this->time->getCurrentTime() + max(0, $delay)
          : 0,
        'lease_until' => NULL,
        'last_error' => substr($error, 0, 4000),
        'updated' => $this->time->getCurrentTime(),
      ])
      ->condition('id', $id)
      ->condition('status', 'processing')
      ->execute();
    return (int) $updated === 1;
  }

  /**
   * Returns snapshot tokens for outbox identifiers.
   *
   * @param array<int|string> $ids
   *   Outbox row identifiers.
   *
   * @return string[]
   *   Snapshot tokens.
   */
  private function snapshotTokens(array $ids): array {
    return array_map('strval', $this->database->select(self::TABLE, 'event')
      ->fields('event', ['snapshot_token'])
      ->condition('id', $ids, 'IN')
      ->execute()
      ->fetchCol());
  }

}
