<?php

declare(strict_types=1);

namespace Drupal\Tests\elan_bridge\Unit\Snapshot;

use Drupal\elan_bridge\Snapshot\SnapshotSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Tests immutable TMGMT snapshot conversion.
 */
final class SnapshotSerializerTest extends TestCase {

  /**
   * Tests that source versions are deterministic across input order.
   */
  public function testSourceVersionIsDeterministic(): void {
    $serializer = new SnapshotSerializer();
    $one = [
      'title][0][value' => ['#text' => 'Hello'],
      'body][0][value' => ['#text' => 'World'],
    ];
    $two = array_reverse($one, TRUE);

    self::assertSame(
      $serializer->sourceVersion($one),
      $serializer->sourceVersion($two),
    );
    $two['title][0][value']['#text'] = 'Changed';
    self::assertNotSame(
      $serializer->sourceVersion($one),
      $serializer->sourceVersion($two),
    );
  }

  /**
   * Tests canonical resource and provider metadata serialization.
   */
  public function testCanonicalSnapshot(): void {
    $serializer = new SnapshotSerializer();
    $payload = $serializer->canonical([
      'token' => 'snapshot-token',
      'source_locale' => 'en',
      'target_locale' => 'de',
      'source_version' => 'version-1',
      'submission_id' => 'submission-1',
      'job_id' => 81,
      'job_item_id' => 731,
    ], [
      'field_body][0][value' => [
        '#text' => 'Hello',
        '#format' => 'basic_html',
        '#max_length' => 500,
      ],
    ], 'Homepage');

    self::assertSame('snapshot-token', $payload['resource']['id']);
    self::assertSame('tmgmt_job_item', $payload['resource']['type']);
    self::assertSame('Homepage', $payload['resource']['title']);
    self::assertSame('field_body][0][value', $payload['keys'][0]['key']);
    self::assertSame(hash('sha256', 'Hello'), $payload['keys'][0]['source_digest']);
    self::assertSame('basic_html', $payload['metadata']['data_items']['field_body][0][value']['format']);
    self::assertSame(500, $payload['metadata']['data_items']['field_body][0][value']['max_length']);
  }

  /**
   * Tests exact opaque key enforcement for result import.
   */
  public function testTranslationDataRejectsUnknownKey(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown TMGMT data-item key');

    (new SnapshotSerializer())->translationData(
      ['unknown' => 'Hallo'],
      ['field_title][0][value' => ['#text' => 'Hello']],
    );
  }

  /**
   * Tests that provider-native maximum lengths are enforced on writeback.
   */
  public function testTranslationDataEnforcesMaximumLength(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('exceeds its maximum length of 4 characters');

    (new SnapshotSerializer())->translationData(
      ['field_title][0][value' => 'Hallo'],
      [
        'field_title][0][value' => [
          '#text' => 'Hello',
          '#max_length' => 4,
        ],
      ],
    );
  }

}
