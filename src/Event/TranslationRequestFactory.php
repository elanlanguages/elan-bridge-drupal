<?php

declare(strict_types=1);

namespace Drupal\elan_bridge\Event;

/**
 * Builds strict cms.translation.requested event payloads.
 */
final class TranslationRequestFactory {

  private const EVENT_TYPE = 'cms.translation.requested';

  private const RESOURCE_TYPE = 'tmgmt_job_item';

  private const SCHEMA_VERSION = 1;

  /**
   * Builds one canonical translation-request event.
   *
   * @return array<string, mixed>
   *   The strict event payload accepted by Bridge.
   */
  public function create(
    string $event_id,
    \DateTimeInterface $occurred_at,
    string $resource_id,
    string $source_locale,
    string $version,
    string $target_locale,
    int $binding_id,
    string|int $tmgmt_job_id,
    string|int $tmgmt_job_item_id,
    string $submission_id,
  ): array {
    $job_id = (string) $tmgmt_job_id;
    $job_item_id = (string) $tmgmt_job_item_id;
    $required = [
      'event_id' => $event_id,
      'resource.id' => $resource_id,
      'resource.source_locale' => $source_locale,
      'resource.version' => $version,
      'translation.target_locale' => $target_locale,
      'translation.tmgmt_job_id' => $job_id,
      'translation.tmgmt_job_item_id' => $job_item_id,
      'translation.submission_id' => $submission_id,
    ];
    foreach ($required as $field => $value) {
      if ($value === '') {
        throw new \InvalidArgumentException(
          sprintf('%s cannot be empty.', $field),
        );
      }
    }
    if ($binding_id <= 0) {
      throw new \InvalidArgumentException(
        'translation.binding_id must be positive.',
      );
    }

    $occurred_at_utc = \DateTimeImmutable::createFromInterface($occurred_at)
      ->setTimezone(new \DateTimeZone('UTC'))
      ->format('Y-m-d\TH:i:s\Z');

    return [
      'schema_version' => self::SCHEMA_VERSION,
      'event_id' => $event_id,
      'type' => self::EVENT_TYPE,
      'occurred_at' => $occurred_at_utc,
      'resource' => [
        'id' => $resource_id,
        'type' => self::RESOURCE_TYPE,
        'source_locale' => $source_locale,
        'version' => $version,
      ],
      'translation' => [
        'target_locale' => $target_locale,
        'binding_id' => $binding_id,
        'tmgmt_job_id' => $job_id,
        'tmgmt_job_item_id' => $job_item_id,
        'submission_id' => $submission_id,
      ],
    ];
  }

}
