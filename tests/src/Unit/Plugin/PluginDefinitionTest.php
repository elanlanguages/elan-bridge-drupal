<?php

declare(strict_types=1);

namespace Drupal\Tests\elan_bridge\Unit\Plugin;

use Drupal\Component\Annotation\Doctrine\SimpleAnnotationReader;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\elan_bridge\Event\EventOutbox;
use Drupal\elan_bridge\Plugin\QueueWorker\EventDeliveryWorker;
use Drupal\elan_bridge\Plugin\tmgmt\Translator\ElanBridgeTranslator;
use Drupal\elan_bridge\Translator\ElanBridgeTranslatorUi;
use Drupal\tmgmt\Annotation\TranslatorPlugin as TranslatorPluginAnnotation;
use Drupal\tmgmt\Attribute\TranslatorPlugin as TranslatorPluginAttribute;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Drupal can load and discover both module plugins.
 */
final class PluginDefinitionTest extends TestCase {

  /**
   * Tests the attribute and legacy annotation define the same translator.
   */
  public function testTranslatorDefinition(): void {
    $reflection = new \ReflectionClass(ElanBridgeTranslator::class);
    $attributes = $reflection->getAttributes(TranslatorPluginAttribute::class);

    self::assertCount(1, $attributes);

    // Reflection exposes attribute arguments even on TMGMT 1.17, where the
    // TranslatorPlugin attribute class does not exist yet.
    $attribute_values = $attributes[0]->getArguments();
    $attribute_definition = $this->normalizeDefinition($attribute_values);

    $reader = new SimpleAnnotationReader();
    $reader->addNamespace('Drupal\\tmgmt\\Annotation');
    $reader->addNamespace('Drupal\\Core\\Annotation');
    $annotation = $reader->getClassAnnotation(
      $reflection,
      TranslatorPluginAnnotation::class,
    );

    self::assertInstanceOf(TranslatorPluginAnnotation::class, $annotation);
    $annotation_definition = $this->normalizeDefinition($annotation->get());

    $expected = [
      'id' => 'elan_bridge',
      'label' => 'ELAN AI Bridge',
      'description' => 'Submits translation jobs to an exact ELAN AI Bridge project binding.',
      'default_settings' => ['binding_id' => 0],
      'ui' => ElanBridgeTranslatorUi::class,
      'files' => FALSE,
      'map_remote_languages' => TRUE,
    ];
    self::assertSame($expected, $attribute_definition);
    self::assertSame($expected, $annotation_definition);
    self::assertSame($attribute_definition, $annotation_definition);
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

  /**
   * Selects and normalizes translator metadata for strict comparisons.
   *
   * @param array<string, mixed> $definition
   *   A translator plugin definition.
   *
   * @return array<string, mixed>
   *   The normalized compatibility fields.
   */
  private function normalizeDefinition(array $definition): array {
    $label = $definition['label'];
    $description = $definition['description'];

    self::assertInstanceOf(TranslatableMarkup::class, $label);
    self::assertInstanceOf(TranslatableMarkup::class, $description);

    return [
      'id' => $definition['id'],
      'label' => $label->getUntranslatedString(),
      'description' => $description->getUntranslatedString(),
      'default_settings' => $definition['default_settings'],
      'ui' => $definition['ui'],
      'files' => $definition['files'],
      'map_remote_languages' => $definition['map_remote_languages'],
    ];
  }

}
