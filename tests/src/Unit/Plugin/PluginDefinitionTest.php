<?php

declare(strict_types=1);

namespace Drupal\Tests\elan_bridge\Unit\Plugin;

use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\elan_bridge\Event\EventOutbox;
use Drupal\elan_bridge\Plugin\QueueWorker\EventDeliveryWorker;
use Drupal\elan_bridge\Plugin\tmgmt\Translator\ElanBridgeTranslator;
use Drupal\tmgmt\Attribute\TranslatorPlugin;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Drupal can load and discover both module plugins.
 */
final class PluginDefinitionTest extends TestCase {

  /**
   * Tests the native TMGMT translator definition.
   */
  public function testTranslatorDefinition(): void {
    $reflection = new \ReflectionClass(ElanBridgeTranslator::class);
    $attributes = $reflection->getAttributes(TranslatorPlugin::class);

    self::assertCount(1, $attributes);
    $definition = $attributes[0]->newInstance();
    self::assertSame('elan_bridge', $definition->id);
    self::assertFalse($definition->files);
    self::assertTrue($definition->map_remote_languages);
  }

  /**
   * Tests that the queue worker ID exactly matches the queue name.
   */
  public function testQueueWorkerDefinition(): void {
    $reflection = new \ReflectionClass(EventDeliveryWorker::class);
    $attributes = $reflection->getAttributes(QueueWorker::class);

    self::assertCount(1, $attributes);
    $definition = $attributes[0]->newInstance();
    self::assertSame(EventOutbox::QUEUE_ID, $definition->id);
  }

}
