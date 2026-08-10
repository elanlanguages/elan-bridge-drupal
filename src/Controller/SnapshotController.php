<?php

declare(strict_types=1);

namespace Drupal\elan_bridge\Controller;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\elan_bridge\Connection\ConnectionSettings;
use Drupal\elan_bridge\Snapshot\SnapshotRepository;
use Drupal\elan_bridge\Snapshot\SnapshotSerializer;
use Drupal\tmgmt\Data;
use Drupal\tmgmt\JobItemInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Exposes immutable TMGMT snapshots to an authenticated Bridge connection.
 */
final class SnapshotController {

  private const MAX_RESULT_BYTES = 10 * 1024 * 1024;

  /**
   * Constructs the snapshot controller.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Data $data,
    private readonly SnapshotRepository $snapshots,
    private readonly SnapshotSerializer $serializer,
    private readonly ConnectionSettings $settings,
    private readonly LockBackendInterface $lock,
    private readonly Connection $database,
  ) {}

  /**
   * Returns one canonical ResourceWithTranslations snapshot.
   */
  public function view(Request $request, string $token): JsonResponse {
    $this->requireAuthentication($request);
    $snapshot = $this->requireSnapshot($token);
    $this->requireActiveSnapshot($snapshot);
    $this->requireJobItem($snapshot);

    return $this->response($this->serializer->canonical(
      $snapshot,
      $snapshot['source_data'],
      (string) $snapshot['source_label'],
    ));
  }

  /**
   * Imports one complete translated key set into TMGMT's review stage.
   */
  public function writeTranslations(Request $request, string $token): JsonResponse {
    $this->requireAuthentication($request);
    if (!$this->lock->acquire('elan_bridge_snapshot:' . $token, 30.0)) {
      throw new ConflictHttpException('A result for this snapshot is already being applied.');
    }

    try {
      return $this->applyTranslations($request, $token);
    }
    finally {
      $this->lock->release('elan_bridge_snapshot:' . $token);
    }
  }

  /**
   * Validates and applies a result while holding the per-snapshot lock.
   */
  private function applyTranslations(Request $request, string $token): JsonResponse {
    $snapshot = $this->requireSnapshot($token);
    $this->requireActiveSnapshot($snapshot);
    $payload = $this->decodeResult($request);
    $locale = (string) $payload['locale'];
    if (!hash_equals((string) $snapshot['target_locale'], $locale)) {
      throw new ConflictHttpException('The result locale does not match this snapshot.');
    }

    /** @var array<string, mixed> $values */
    $values = $payload['values'];
    $source_data = $snapshot['source_data'];
    $expected_keys = array_map('strval', array_keys($source_data));
    $actual_keys = array_map('strval', array_keys($values));
    sort($expected_keys, SORT_STRING);
    sort($actual_keys, SORT_STRING);
    if ($actual_keys !== $expected_keys) {
      throw new UnprocessableEntityHttpException(
        'The result must contain exactly every source key in this snapshot.',
      );
    }

    ksort($values, SORT_STRING);
    $digest = hash('sha256', json_encode(
      ['locale' => $locale, 'values' => $values],
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ));
    if (!empty($snapshot['result_digest'])) {
      if (!hash_equals((string) $snapshot['result_digest'], $digest)) {
        throw new ConflictHttpException('A different result was already applied to this snapshot.');
      }
      return $this->resultResponse($token, $locale, 0, count($values));
    }

    $item = $this->requireJobItem($snapshot);
    if (!$item->isActive()) {
      throw new ConflictHttpException('The TMGMT JobItem is no longer active.');
    }
    $translator = $item->getTranslator();
    if ($translator === NULL || $translator->isAutoAccept()) {
      throw new ConflictHttpException(
        'The ELAN Bridge translator must have automatic acceptance disabled.',
      );
    }

    try {
      $translation_data = $this->serializer->translationData($values, $source_data);
    }
    catch (\InvalidArgumentException $exception) {
      throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
    }

    $transaction = $this->database->startTransaction();
    try {
      $item->addTranslatedData(
        $this->data->unflatten($translation_data),
        [],
        TMGMT_DATA_ITEM_STATE_TRANSLATED,
      );
      if (!$item->isNeedsReview()) {
        throw new \UnexpectedValueException(
          'TMGMT did not move the complete result to Needs review.',
        );
      }
      if (!$this->snapshots->recordResult($token, $digest, $values)) {
        throw new ConflictHttpException(
          'The snapshot changed state while its result was being applied.',
        );
      }
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }

    return $this->resultResponse($token, $locale, count($values), 0);
  }

  /**
   * Requires the exact site bearer credential without session fallback.
   */
  private function requireAuthentication(Request $request): void {
    $authorization = (string) $request->headers->get('Authorization', '');
    $secret = $this->settings->bearerSecret();
    if ($secret === ''
      || preg_match('/^Bearer[ \t]+(.+)$/i', $authorization, $matches) !== 1
      || !hash_equals($secret, $matches[1])) {
      throw new HttpException(
        401,
        'A valid ELAN Bridge bearer credential is required.',
        NULL,
        ['WWW-Authenticate' => 'Bearer'],
      );
    }
  }

  /**
   * Loads a snapshot without leaking whether authentication failed.
   *
   * @return array<string, mixed>
   *   Persisted snapshot data.
   */
  private function requireSnapshot(string $token): array {
    $snapshot = $this->snapshots->load($token);
    if ($snapshot === NULL) {
      throw new NotFoundHttpException('The snapshot does not exist.');
    }
    return $snapshot;
  }

  /**
   * Rejects handles whose lifecycle makes further access unsafe.
   *
   * @param array<string, mixed> $snapshot
   *   Persisted snapshot data.
   */
  private function requireActiveSnapshot(array $snapshot): void {
    if (in_array($snapshot['status'], ['cancelled', 'superseded'], TRUE)) {
      throw new ConflictHttpException('The snapshot is no longer active.');
    }
  }

  /**
   * Loads and verifies the mapped TMGMT JobItem.
   *
   * @param array<string, mixed> $snapshot
   *   Persisted snapshot data.
   *
   * @return \Drupal\tmgmt\JobItemInterface
   *   The exact JobItem pinned by the snapshot.
   */
  private function requireJobItem(array $snapshot): JobItemInterface {
    $item = $this->entityTypeManager
      ->getStorage('tmgmt_job_item')
      ->load((int) $snapshot['job_item_id']);
    if (!$item instanceof JobItemInterface
      || (int) $item->getJobId() !== (int) $snapshot['job_id']) {
      throw new ConflictHttpException('The mapped TMGMT JobItem is unavailable.');
    }
    if ($item->isAborted() || $item->isAccepted()) {
      throw new ConflictHttpException('The mapped TMGMT JobItem is no longer writable.');
    }
    return $item;
  }

  /**
   * Parses the exact Bridge set-translations input shape.
   *
   * @return array{locale: string, values: array<string, mixed>}
   *   Validated top-level result data.
   */
  private function decodeResult(Request $request): array {
    if (strlen($request->getContent()) > self::MAX_RESULT_BYTES) {
      throw new HttpException(413, 'The translation result is too large.');
    }
    try {
      $payload = json_decode($request->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new UnprocessableEntityHttpException('The request body must be valid JSON.', $exception);
    }
    if (!is_array($payload)) {
      throw new UnprocessableEntityHttpException('The request body must be a JSON object.');
    }
    $keys = array_keys($payload);
    sort($keys, SORT_STRING);
    if ($keys !== ['locale', 'values']
      || !is_string($payload['locale'])
      || $payload['locale'] === ''
      || !is_array($payload['values'])
      || array_is_list($payload['values'])) {
      throw new UnprocessableEntityHttpException(
        'The request must contain only a locale string and a values object.',
      );
    }
    return $payload;
  }

  /**
   * Builds the canonical SetTranslationsResult response.
   */
  private function resultResponse(
    string $token,
    string $locale,
    int $written,
    int $skipped,
  ): JsonResponse {
    return $this->response([
      'resource_id' => $token,
      'locale' => $locale,
      'keys_written' => $written,
      'keys_skipped' => $skipped,
      'errors' => [],
    ]);
  }

  /**
   * Returns private JSON that intermediaries must never cache.
   *
   * @param array<string, mixed> $payload
   *   Response document.
   */
  private function response(array $payload): JsonResponse {
    return new JsonResponse($payload, 200, [
      'Cache-Control' => 'no-store, private',
    ]);
  }

}
