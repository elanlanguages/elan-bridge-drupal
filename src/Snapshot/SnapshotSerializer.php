<?php

declare(strict_types=1);

namespace Drupal\elan_bridge\Snapshot;

/**
 * Converts cached TMGMT data items to and from the Bridge CMS contract.
 */
final class SnapshotSerializer {

  /**
   * Hashes the exact opaque source-key/value snapshot deterministically.
   *
   * @param array<string, array<string, mixed>> $flat_data
   *   Flattened TMGMT data from Data::filterTranslatable().
   */
  public function sourceVersion(array $flat_data): string {
    $source = [];
    foreach ($flat_data as $key => $item) {
      $source[(string) $key] = (string) ($item['#text'] ?? '');
    }
    ksort($source, SORT_STRING);
    return hash('sha256', json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }

  /**
   * Builds the canonical resource-with-translations response.
   *
   * @param array<string, mixed> $snapshot
   *   Persisted snapshot metadata.
   * @param array<string, array<string, mixed>> $flat_data
   *   Flattened TMGMT JobItem data.
   * @param string $label
   *   TMGMT source label.
   *
   * @return array<string, mixed>
   *   Canonical Bridge CMS payload.
   */
  public function canonical(array $snapshot, array $flat_data, string $label): array {
    ksort($flat_data, SORT_STRING);
    $keys = [];
    $translations = [];
    $data_item_metadata = [];
    foreach ($flat_data as $key => $item) {
      $opaque_key = (string) $key;
      $source_value = (string) ($item['#text'] ?? '');
      $keys[] = [
        'key' => $opaque_key,
        'source_value' => $source_value,
        'source_locale' => (string) $snapshot['source_locale'],
        'source_digest' => hash('sha256', $source_value),
      ];
      if (isset($item['#translation']['#text'])) {
        $translations[$opaque_key] = [
          (string) $snapshot['target_locale'] => (string) $item['#translation']['#text'],
        ];
      }
      $metadata = $this->dataItemMetadata($item);
      if ($metadata !== []) {
        $data_item_metadata[$opaque_key] = $metadata;
      }
    }

    $metadata = [
      'snapshot_version' => (string) $snapshot['source_version'],
      'submission_id' => (string) $snapshot['submission_id'],
      'tmgmt_job_id' => (string) $snapshot['job_id'],
      'tmgmt_job_item_id' => (string) $snapshot['job_item_id'],
      'target_locale' => (string) $snapshot['target_locale'],
      'data_items' => $data_item_metadata,
    ];

    return [
      'resource' => [
        'id' => (string) $snapshot['token'],
        'type' => 'tmgmt_job_item',
        'title' => $label,
        'metadata' => $metadata,
      ],
      'source_locale' => (string) $snapshot['source_locale'],
      'keys' => $keys,
      // Preserve the JSON map type when there are no existing translations.
      'translations' => (object) $translations,
      'metadata' => $metadata,
    ];
  }

  /**
   * Validates translated values and builds flattened TMGMT translation data.
   *
   * @param array<string, mixed> $values
   *   Translated values keyed by the exact opaque TMGMT path.
   * @param array<string, array<string, mixed>> $source_data
   *   Flattened source data used to validate keys.
   *
   * @return array<string, array<string, string>>
   *   Flattened data ready for Data::unflatten().
   */
  public function translationData(array $values, array $source_data): array {
    $translated = [];
    foreach ($values as $key => $value) {
      $opaque_key = (string) $key;
      if (!array_key_exists($opaque_key, $source_data)) {
        throw new \InvalidArgumentException(sprintf('Unknown TMGMT data-item key: %s', $opaque_key));
      }
      if (!is_string($value)) {
        throw new \InvalidArgumentException(sprintf('Translation for %s must be a string.', $opaque_key));
      }
      $max_length = $source_data[$opaque_key]['#max_length'] ?? NULL;
      if (is_numeric($max_length)
        && (int) $max_length > 0
        && mb_strlen($value) > (int) $max_length) {
        throw new \InvalidArgumentException(sprintf(
          'Translation for %s exceeds its maximum length of %d characters.',
          $opaque_key,
          (int) $max_length,
        ));
      }
      $translated[$opaque_key] = [
        '#text' => $value,
        '#origin' => 'remote',
      ];
    }
    return $translated;
  }

  /**
   * Preserves provider-native constraints without changing canonical keys.
   *
   * @param array<string, mixed> $item
   *   One flattened TMGMT data item.
   *
   * @return array<string, mixed>
   *   JSON-safe metadata retained for the Drupal connector.
   */
  private function dataItemMetadata(array $item): array {
    $metadata = [];
    foreach (['#label', '#parent_label', '#format', '#max_length', '#escape'] as $source_key) {
      if (array_key_exists($source_key, $item)) {
        $metadata[ltrim($source_key, '#')] = $item[$source_key];
      }
    }
    return $metadata;
  }

}
