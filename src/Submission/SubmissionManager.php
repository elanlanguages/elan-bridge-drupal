<?php

declare(strict_types=1);

namespace Drupal\elan_bridge\Submission;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\elan_bridge\Connection\ConnectionSettings;
use Drupal\elan_bridge\Event\EventOutbox;
use Drupal\elan_bridge\Event\TranslationRequestFactory;
use Drupal\elan_bridge\Snapshot\SnapshotRepository;
use Drupal\elan_bridge\Snapshot\SnapshotSerializer;
use Drupal\tmgmt\Data;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;
use Psr\Log\LoggerInterface;

/**
 * Converts one TMGMT job into durable, immutable Bridge submissions.
 */
final class SubmissionManager {

  /**
   * Constructs the submission manager.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly SnapshotRepository $snapshots,
    private readonly SnapshotSerializer $serializer,
    private readonly EventOutbox $outbox,
    private readonly TranslationRequestFactory $eventFactory,
    private readonly ConnectionSettings $connectionSettings,
    private readonly Data $data,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Persists every JobItem and event before the translator becomes active.
   *
   * @return array<int, array<string, string|int>>
   *   Submission identities keyed as a sequential list.
   */
  public function submitJob(JobInterface $job, int $binding_id): array {
    if ($binding_id < 1) {
      throw new \InvalidArgumentException('The Bridge binding ID must be positive.');
    }
    if ($job->id() === NULL) {
      throw new \InvalidArgumentException('The TMGMT job must be saved before submission.');
    }

    $source_locale = trim((string) $job->getRemoteSourceLanguage());
    $target_locale = trim((string) $job->getRemoteTargetLanguage());
    if ($source_locale === '' || $target_locale === '') {
      throw new \InvalidArgumentException('The TMGMT remote language mappings are incomplete.');
    }

    $transaction = $this->database->startTransaction();
    $submissions = [];
    try {
      foreach ($job->getItems() as $item) {
        $submissions[] = $this->submitItem(
          $job,
          $item,
          $binding_id,
          $source_locale,
          $target_locale,
        );
      }
      if ($submissions === []) {
        throw new \InvalidArgumentException('The TMGMT job has no translatable items.');
      }
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }

    // Commit every durable row before publishing to a potentially external
    // Queue API backend. Cron recovers any wake-up that cannot be published.
    unset($transaction);
    foreach ($submissions as $submission) {
      if (!$this->outbox->schedule((int) $submission['outbox_id'])) {
        $this->logger->error(
          'Could not schedule ELAN Bridge outbox event @event_id; cron will retry it.',
          ['@event_id' => $submission['event_id']],
        );
      }
    }
    return $submissions;
  }

  /**
   * Cancels snapshots and undelivered events for an aborted TMGMT job.
   */
  public function abortJob(JobInterface $job): void {
    if ($job->id() === NULL) {
      return;
    }
    $transaction = $this->database->startTransaction();
    try {
      $tokens = $this->snapshots->tokensForJob((int) $job->id());
      $this->snapshots->cancelJob((int) $job->id());
      $this->outbox->cancelBySnapshots($tokens);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Persists one immutable JobItem and its exact outbound event.
   *
   * @return array<string, string|int>
   *   Stable identities for diagnostics and the TMGMT remote mapping.
   */
  private function submitItem(
    JobInterface $job,
    JobItemInterface $item,
    int $binding_id,
    string $source_locale,
    string $target_locale,
  ): array {
    if ($item->id() === NULL) {
      throw new \InvalidArgumentException('Every TMGMT JobItem must be saved before submission.');
    }
    $existing = $this->snapshots->activeForJobItem((int) $item->id());
    if ($existing !== NULL) {
      if ((int) $existing['job_id'] !== (int) $job->id()
        || (int) $existing['binding_id'] !== $binding_id
        || (string) $existing['source_locale'] !== $source_locale
        || (string) $existing['target_locale'] !== $target_locale) {
        throw new \LogicException(
          'This TMGMT JobItem already has an active submission with different routing.',
        );
      }
      $outbox_id = $this->outbox->idForSnapshot((string) $existing['token']);
      if ($outbox_id === NULL) {
        throw new \UnexpectedValueException(
          'The active ELAN Bridge snapshot has no durable outbox event.',
        );
      }
      return [
        'token' => (string) $existing['token'],
        'event_id' => (string) $existing['event_id'],
        'submission_id' => (string) $existing['submission_id'],
        'job_item_id' => (int) $item->id(),
        'outbox_id' => $outbox_id,
      ];
    }
    $source_data = $this->data->filterTranslatable($item->getData());
    if ($source_data === []) {
      throw new \InvalidArgumentException(sprintf(
        'TMGMT JobItem %s has no translatable text.',
        (string) $item->id(),
      ));
    }

    $token = bin2hex(random_bytes(32));
    $event_id = $this->uuid->generate();
    $submission_id = $this->uuid->generate();
    $source_version = $this->serializer->sourceVersion($source_data);
    $event = $this->eventFactory->create(
      $event_id,
      (new \DateTimeImmutable('@' . $this->time->getCurrentTime())),
      $token,
      $source_locale,
      $source_version,
      $target_locale,
      $binding_id,
      (string) $job->id(),
      (string) $item->id(),
      $submission_id,
    );

    $this->snapshots->create([
      'token' => $token,
      'submission_id' => $submission_id,
      'event_id' => $event_id,
      'active_key' => (string) $item->id(),
      'job_id' => (int) $job->id(),
      'job_item_id' => (int) $item->id(),
      'binding_id' => $binding_id,
      'source_locale' => $source_locale,
      'target_locale' => $target_locale,
      'source_version' => $source_version,
      'source_label' => mb_substr((string) $item->getSourceLabel(), 0, 255),
      'source_data' => $source_data,
    ]);
    $outbox_id = $this->outbox->persist(
      $event,
      $token,
      $this->connectionSettings->deliveryFingerprint(),
    );
    $item->addRemoteMapping(NULL, $token, [
      'remote_identifier_2' => $submission_id,
      'remote_identifier_3' => (string) $binding_id,
      'remote_data' => [
        'schema_version' => 1,
        'event_id' => $event_id,
        'source_version' => $source_version,
      ],
    ]);

    return [
      'token' => $token,
      'event_id' => $event_id,
      'submission_id' => $submission_id,
      'job_item_id' => (int) $item->id(),
      'outbox_id' => $outbox_id,
    ];
  }

}
