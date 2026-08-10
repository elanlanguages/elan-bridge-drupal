<?php

declare(strict_types=1);

namespace Drupal\Tests\elan_bridge\Unit\Event;

use Drupal\elan_bridge\Event\TranslationRequestFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests strict translation-request event construction.
 */
final class TranslationRequestFactoryTest extends TestCase {

  /**
   * Tests the complete event shape and scalar serialization.
   */
  public function testCreatesStrictTranslationRequestEvent(): void {
    $factory = new TranslationRequestFactory();

    $event = $factory->create(
      event_id: '018f6f61-6a48-7abc-9234-123456789abc',
      occurred_at: new \DateTimeImmutable('2026-08-10T14:00:00+02:00'),
      resource_id: 'tji_nP4hS8aQx2kLm9Vr',
      source_locale: 'en',
      version: 'revision:42:sha256:abc123',
      target_locale: 'de',
      binding_id: 123,
      tmgmt_job_id: 81,
      tmgmt_job_item_id: '731',
      submission_id: '018f6f62-6a48-7abc-9234-123456789abc',
    );

    self::assertSame([
      'schema_version' => 1,
      'event_id' => '018f6f61-6a48-7abc-9234-123456789abc',
      'type' => 'cms.translation.requested',
      'occurred_at' => '2026-08-10T12:00:00Z',
      'resource' => [
        'id' => 'tji_nP4hS8aQx2kLm9Vr',
        'type' => 'tmgmt_job_item',
        'source_locale' => 'en',
        'version' => 'revision:42:sha256:abc123',
      ],
      'translation' => [
        'target_locale' => 'de',
        'binding_id' => 123,
        'tmgmt_job_id' => '81',
        'tmgmt_job_item_id' => '731',
        'submission_id' => '018f6f62-6a48-7abc-9234-123456789abc',
      ],
    ], $event);
  }

  /**
   * Tests that the Bridge routing identifier must be positive.
   */
  public function testRejectsNonPositiveBindingId(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('translation.binding_id must be positive.');

    (new TranslationRequestFactory())->create(
      event_id: 'event-1',
      occurred_at: new \DateTimeImmutable('2026-08-10T12:00:00Z'),
      resource_id: 'snapshot-1',
      source_locale: 'en',
      version: 'version-1',
      target_locale: 'de',
      binding_id: 0,
      tmgmt_job_id: '81',
      tmgmt_job_item_id: '731',
      submission_id: 'submission-1',
    );
  }

  /**
   * Tests that required string values cannot be empty.
   */
  public function testRejectsEmptyRequiredValue(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('resource.source_locale cannot be empty.');

    (new TranslationRequestFactory())->create(
      event_id: 'event-1',
      occurred_at: new \DateTimeImmutable('2026-08-10T12:00:00Z'),
      resource_id: 'snapshot-1',
      source_locale: '',
      version: 'version-1',
      target_locale: 'de',
      binding_id: 123,
      tmgmt_job_id: '81',
      tmgmt_job_item_id: '731',
      submission_id: 'submission-1',
    );
  }

}
