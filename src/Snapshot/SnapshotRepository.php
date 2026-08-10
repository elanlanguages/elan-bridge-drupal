<?php

declare(strict_types=1);

namespace Drupal\elan_bridge\Snapshot;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Persists opaque handles for immutable TMGMT JobItem submissions.
 */
final class SnapshotRepository {

  private const TABLE = 'elan_bridge_snapshot';

  /**
   * Constructs the snapshot repository.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Persists one snapshot identity.
   *
   * @param array<string, mixed> $snapshot
   *   Complete snapshot metadata.
   */
  public function create(array $snapshot): void {
    $now = $this->time->getCurrentTime();
    if (!is_array($snapshot['source_data'] ?? NULL)) {
      throw new \InvalidArgumentException('Snapshot source_data must be an array.');
    }
    $snapshot['source_data'] = json_encode(
      $snapshot['source_data'],
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    $this->database->insert(self::TABLE)
      ->fields($snapshot + [
        'status' => 'pending',
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();
  }

  /**
   * Loads one snapshot by its opaque token.
   *
   * @return array<string, mixed>|null
   *   Snapshot data, or NULL if it does not exist.
   */
  public function load(string $token): ?array {
    $row = $this->database->select(self::TABLE, 'snapshot')
      ->fields('snapshot')
      ->condition('token', $token)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $this->decodeRow($row) : NULL;
  }

  /**
   * Returns an existing durable submission for an interrupted TMGMT request.
   *
   * @return array<string, mixed>|null
   *   Active snapshot data, or NULL when a fresh submission is required.
   */
  public function activeForJobItem(int $job_item_id): ?array {
    $row = $this->database->select(self::TABLE, 'snapshot')
      ->fields('snapshot')
      ->condition('job_item_id', $job_item_id)
      ->condition('status', ['pending', 'queued'], 'IN')
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return is_array($row) ? $this->decodeRow($row) : NULL;
  }

  /**
   * Updates a snapshot lifecycle state.
   *
   * @param string $token
   *   Opaque snapshot handle.
   * @param string $status
   *   New lifecycle state.
   * @param string[] $expected_statuses
   *   Optional states from which this transition is permitted.
   */
  public function updateStatus(
    string $token,
    string $status,
    array $expected_statuses = [],
  ): bool {
    $update = $this->database->update(self::TABLE)
      ->fields([
        'status' => $status,
        'updated' => $this->time->getCurrentTime(),
      ])
      ->condition('token', $token);
    if ($expected_statuses !== []) {
      $update->condition('status', $expected_statuses, 'IN');
    }
    return (int) $update->execute() === 1;
  }

  /**
   * Records one successfully imported, idempotent result.
   *
   * @param string $token
   *   Opaque snapshot handle.
   * @param string $digest
   *   Digest of the canonical locale and translated values.
   * @param array<string, string> $values
   *   Exact translated values accepted for this snapshot.
   */
  public function recordResult(string $token, string $digest, array $values): bool {
    $now = $this->time->getCurrentTime();
    $update = $this->database->update(self::TABLE)
      ->fields([
        'result_digest' => $digest,
        'result_payload' => json_encode(
          $values,
          JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ),
        'result_received' => $now,
        'status' => 'needs_review',
        'active_key' => NULL,
        'updated' => $now,
      ])
      ->condition('token', $token)
      ->condition('status', ['pending', 'queued'], 'IN')
      ->isNull('result_digest');
    return (int) $update->execute() === 1;
  }

  /**
   * Returns every active snapshot token associated with a TMGMT job.
   *
   * @return string[]
   *   Opaque snapshot tokens.
   */
  public function tokensForJob(int $job_id): array {
    $tokens = $this->database->select(self::TABLE, 'snapshot')
      ->fields('snapshot', ['token'])
      ->condition('job_id', $job_id)
      ->condition('status', ['cancelled'], 'NOT IN')
      ->execute()
      ->fetchCol();
    return array_values(array_map('strval', $tokens));
  }

  /**
   * Cancels every snapshot belonging to a TMGMT job.
   */
  public function cancelJob(int $job_id): void {
    $this->database->update(self::TABLE)
      ->fields([
        'status' => 'cancelled',
        'active_key' => NULL,
        'updated' => $this->time->getCurrentTime(),
      ])
      ->condition('job_id', $job_id)
      ->condition('status', ['needs_review', 'cancelled'], 'NOT IN')
      ->execute();
  }

  /**
   * Decodes JSON fields from one database row.
   *
   * @param array<string, mixed> $row
   *   Raw database values.
   *
   * @return array<string, mixed>
   *   Normalized snapshot data.
   */
  private function decodeRow(array $row): array {
    $source_data = json_decode((string) $row['source_data'], TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($source_data)) {
      throw new \UnexpectedValueException('The stored snapshot source data is invalid.');
    }
    $row['source_data'] = $source_data;
    return $row;
  }

}
