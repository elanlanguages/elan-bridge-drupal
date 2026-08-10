<?php

declare(strict_types=1);

namespace Drupal\elan_bridge\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\elan_bridge\Event\EventOutbox;
use Drupal\elan_bridge\Http\BridgeClient;
use Drupal\elan_bridge\Snapshot\SnapshotRepository;
use Drupal\tmgmt\JobItemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Delivers durable ELAN Bridge events from Drupal's Queue API.
 */
#[QueueWorker(
  id: 'elan_bridge_delivery',
  title: new TranslatableMarkup('ELAN Bridge event delivery'),
  cron: ['time' => 30],
)]
final class EventDeliveryWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the event-delivery worker.
   *
   * @param array<string, mixed> $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin identifier.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\elan_bridge\Event\EventOutbox $outbox
   *   Durable event outbox.
   * @param \Drupal\elan_bridge\Http\BridgeClient $bridgeClient
   *   Signed Bridge HTTP client.
   * @param \Drupal\elan_bridge\Snapshot\SnapshotRepository $snapshots
   *   Snapshot repository.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   Module logger.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EventOutbox $outbox,
    private readonly BridgeClient $bridgeClient,
    private readonly SnapshotRepository $snapshots,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('elan_bridge.event_outbox'),
      $container->get('elan_bridge.bridge_client'),
      $container->get('elan_bridge.snapshot_repository'),
      $container->get('entity_type.manager'),
      $container->get('logger.channel.elan_bridge'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data) || !isset($data['outbox_id']) || !is_numeric($data['outbox_id'])) {
      $this->logger->warning('Ignored a malformed ELAN Bridge delivery queue item.');
      return;
    }

    $outbox_id = (int) $data['outbox_id'];
    if ($outbox_id <= 0) {
      $this->logger->warning('Ignored an ELAN Bridge delivery queue item with an invalid outbox ID.');
      return;
    }

    $row = $this->outbox->beginAttempt($outbox_id);
    if ($row === NULL) {
      // Missing and terminal outbox records require no further work.
      return;
    }

    $snapshot_token = (string) ($row['snapshot_token'] ?? '');
    $snapshot = $snapshot_token === '' ? NULL : $this->snapshots->load($snapshot_token);
    if ($snapshot === NULL) {
      $this->markPermanentFailure(
        $outbox_id,
        $snapshot_token,
        NULL,
        $snapshot_token === ''
          ? 'The outbox record has no snapshot token.'
          : 'The referenced TMGMT snapshot no longer exists.',
      );
      return;
    }

    $event_id = (string) ($row['event_id'] ?? '');
    $payload = (string) ($row['payload'] ?? '');
    if ($event_id === '' || $payload === '') {
      $this->markPermanentFailure(
        $outbox_id,
        $snapshot_token,
        $snapshot,
        'The outbox record has no event ID or payload.',
      );
      return;
    }

    $result = $this->bridgeClient->deliver(
      $event_id,
      $payload,
      (string) ($row['connection_fingerprint'] ?? ''),
    );
    if ($result->delivered()) {
      if ($this->outbox->markDelivered($outbox_id)) {
        $this->snapshots->updateStatus(
          $snapshot_token,
          'queued',
          ['pending'],
        );
        $this->setTranslatorState($snapshot, 'elan_bridge_running');
      }
      return;
    }

    if ($result->retryable()) {
      $error = $result->error !== '' ? $result->error : 'Transient ELAN Bridge delivery failure.';
      $attempt = max(1, (int) ($row['attempts'] ?? 1));
      $delay = EventOutbox::retryDelay($attempt);
      if (!$this->outbox->markRetry($outbox_id, $error, $delay)) {
        return;
      }
      $this->snapshots->updateStatus(
        $snapshot_token,
        'pending',
        ['pending', 'queued'],
      );
      throw new DelayedRequeueException($delay, $error);
    }

    $this->markPermanentFailure(
      $outbox_id,
      $snapshot_token,
      $snapshot,
      $result->error !== '' ? $result->error : 'ELAN Bridge permanently rejected the event.',
    );
  }

  /**
   * Records a terminal failure and exposes it on the related TMGMT job item.
   *
   * @param int $outbox_id
   *   Durable outbox row identifier.
   * @param string $snapshot_token
   *   Opaque snapshot handle.
   * @param array<string, mixed>|null $snapshot
   *   Snapshot metadata, when it is still available.
   * @param string $error
   *   Bounded safe error detail.
   */
  private function markPermanentFailure(
    int $outbox_id,
    string $snapshot_token,
    ?array $snapshot,
    string $error,
  ): void {
    if (!$this->outbox->markDead($outbox_id, $error)) {
      return;
    }
    if ($snapshot_token !== '') {
      $this->snapshots->updateStatus(
        $snapshot_token,
        'failed',
        ['pending', 'queued'],
      );
    }
    if ($snapshot === NULL || (int) ($snapshot['job_item_id'] ?? 0) <= 0) {
      return;
    }

    $job_item_id = (int) $snapshot['job_item_id'];
    try {
      $job_item = $this->entityTypeManager
        ->getStorage('tmgmt_job_item')
        ->load($job_item_id);
      if (!$job_item instanceof JobItemInterface) {
        return;
      }

      $job_item->setTranslatorState('elan_bridge_failed');
      $job_item->save();
      $job_item->addMessage(
        'ELAN Bridge could not accept this translation request: @error',
        ['@error' => $error],
        'error',
      );
    }
    catch (\Throwable $exception) {
      $this->logger->error(
        'Outbox event @outbox_id failed permanently, but TMGMT job item @job_item_id could not be updated: @error',
        [
          '@outbox_id' => $outbox_id,
          '@job_item_id' => $job_item_id,
          '@error' => $exception->getMessage(),
        ],
      );
    }
  }

  /**
   * Best-effort update of the provider-specific TMGMT display state.
   *
   * @param array<string, mixed> $snapshot
   *   Snapshot metadata containing the TMGMT JobItem ID.
   * @param string $state
   *   Translator state machine value.
   */
  private function setTranslatorState(array $snapshot, string $state): void {
    try {
      $job_item = $this->entityTypeManager
        ->getStorage('tmgmt_job_item')
        ->load((int) ($snapshot['job_item_id'] ?? 0));
      if (!$job_item instanceof JobItemInterface) {
        return;
      }
      $job_item->setTranslatorState($state);
      $job_item->save();
    }
    catch (\Throwable $exception) {
      $this->logger->warning(
        'Could not update ELAN Bridge translator state for JobItem @id: @error',
        [
          '@id' => (int) ($snapshot['job_item_id'] ?? 0),
          '@error' => $exception->getMessage(),
        ],
      );
    }
  }

}
