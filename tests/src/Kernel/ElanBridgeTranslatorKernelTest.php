<?php

declare(strict_types=1);

namespace Drupal\Tests\elan_bridge\Kernel;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\elan_bridge\Event\EventOutbox;
use Drupal\elan_bridge\Plugin\tmgmt\Translator\ElanBridgeTranslator;
use Drupal\key\Entity\Key;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\Entity\JobItem;
use Drupal\tmgmt\Entity\Translator;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Exercises the ELAN provider through Drupal and real TMGMT entities.
 *
 * PHPUnit 9 ignores the attribute while Drupal 10's KernelTestBase enables
 * isolation itself; Drupal 11.3+ requires the explicit PHPUnit 11 metadata.
 *
 * @group elan_bridge
 */
#[RunTestsInSeparateProcesses]
final class ElanBridgeTranslatorKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'language',
    'locale',
    'options',
    'tmgmt',
    'tmgmt_test',
    'key',
    'elan_bridge',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // PHPUnit 9 resolves the test suite from the repository, while Drupal 10's
    // legacy SQLite driver resolves its module metadata from the Drupal root.
    $drupal_root = dirname(__DIR__, 3) . '/vendor/drupal';
    if (!chdir($drupal_root)) {
      throw new \RuntimeException('Could not enter the synthetic Drupal test root.');
    }
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('tmgmt_job');
    $this->installEntitySchema('tmgmt_job_item');
    $this->installEntitySchema('tmgmt_message');
    $this->installEntitySchema('tmgmt_remote');
    $this->installSchema('elan_bridge', [
      'elan_bridge_snapshot',
      'elan_bridge_event_outbox',
    ]);
    $this->installConfig(['elan_bridge']);

    ConfigurableLanguage::createFromLangcode('de')->save();
    $this->createKey('elan_bridge_bearer', 'kernel-bearer-secret');
    $this->createKey('elan_bridge_webhook', 'kernel-webhook-secret');
    $this->config('elan_bridge.settings')
      ->set('bridge_url', 'https://bridge.example.test/api')
      ->set('connection_id', 'drupal-kernel-site')
      ->set('bearer_key_id', 'elan_bridge_bearer')
      ->set('webhook_key_id', 'elan_bridge_webhook')
      ->save();
  }

  /**
   * Proves discovery, submission, import, and abort with real TMGMT storage.
   */
  public function testTmgmtLifecycleWithNestedParagraphData(): void {
    $manager = $this->container->get('plugin.manager.tmgmt.translator');
    $definition = $manager->getDefinition('elan_bridge');

    self::assertSame('elan_bridge', $definition['id']);
    self::assertInstanceOf(TranslatableMarkup::class, $definition['label']);
    self::assertFalse($definition['files']);
    self::assertTrue($definition['map_remote_languages']);
    self::assertInstanceOf(
      ElanBridgeTranslator::class,
      $manager->createInstance('elan_bridge'),
    );

    $translator = Translator::create([
      'name' => 'elan_bridge_kernel',
      'label' => 'ELAN Bridge kernel provider',
      'plugin' => 'elan_bridge',
      'auto_accept' => FALSE,
      'settings' => ['binding_id' => 42],
      'remote_languages_mappings' => [
        'en' => 'en-US',
        'de' => 'de-DE',
      ],
    ]);
    $translator->save();

    $source_data = $this->paragraphSourceData();
    $this->container->get('state')->set('tmgmt.test_source_data', $source_data);
    [$job, $item] = $this->createJobItem($translator, 1001);

    // Force the source plugin's nested data into TMGMT's JobItem cache before
    // submission, just as a content/Paragraphs source plugin would do.
    $cached_data = $item->getData();
    self::assertSame(
      'First heading',
      $cached_data['field_components'][0]['field_heading'][0]['value']['#text'],
    );
    self::assertSame(
      'Second heading',
      $cached_data['field_components'][1]['field_heading'][0]['value']['#text'],
    );
    $job->requestTranslation();

    $job = Job::load($job->id());
    $item = JobItem::load($item->id());
    self::assertInstanceOf(JobInterface::class, $job);
    self::assertInstanceOf(JobItemInterface::class, $item);
    self::assertTrue($job->isActive());
    self::assertTrue($item->isActive());
    self::assertSame('elan_bridge_queued', $item->getTranslatorState());

    $mappings = $item->getRemoteMappings();
    self::assertCount(1, $mappings);
    $mapping = reset($mappings);
    $token = $mapping->getRemoteIdentifier1();
    self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    self::assertNotSame('', $mapping->getRemoteIdentifier2());
    self::assertSame('42', $mapping->getRemoteIdentifier3());

    $snapshots = $this->container->get('elan_bridge.snapshot_repository');
    $snapshot = $snapshots->load($token);
    self::assertNotNull($snapshot);
    self::assertSame('en-US', $snapshot['source_locale']);
    self::assertSame('de-DE', $snapshot['target_locale']);
    self::assertSame('pending', $snapshot['status']);
    $source_keys = array_keys($snapshot['source_data']);
    sort($source_keys, SORT_STRING);
    // Each symmetric Paragraph also has a computed, non-translatable leaf;
    // the exact list proves neither internal value escaped into the snapshot.
    self::assertSame([
      'field_components][0][field_body][0][value',
      'field_components][0][field_heading][0][value',
      'field_components][1][field_body][0][value',
      'field_components][1][field_heading][0][value',
    ], $source_keys);

    $outbox = $this->container->get('elan_bridge.event_outbox');
    $outbox_id = $outbox->idForSnapshot($token);
    self::assertNotNull($outbox_id);
    self::assertSame('pending', $outbox->load($outbox_id)['status']);
    self::assertSame(
      1,
      $this->container->get('queue')->get(EventOutbox::QUEUE_ID)->numberOfItems(),
    );

    $values = [];
    foreach ($snapshot['source_data'] as $key => $data_item) {
      $values[$key] = 'DE: ' . $data_item['#text'];
    }
    $request = $this->translationRequest($token, $values);
    $response = $this->container
      ->get('elan_bridge.snapshot_controller')
      ->writeTranslations($request, $token);
    self::assertSame(200, $response->getStatusCode());
    self::assertSame(count($values), $this->responseData($response)['keys_written']);

    $item = JobItem::load($item->id());
    self::assertTrue($item->isNeedsReview());
    foreach ($values as $key => $translation) {
      self::assertSame(
        $translation,
        $item->getData(explode('][', $key))['#translation']['#text'],
      );
    }
    self::assertSame('needs_review', $snapshots->load($token)['status']);

    // Replaying the identical complete result is safe and does not rewrite it.
    $response = $this->container
      ->get('elan_bridge.snapshot_controller')
      ->writeTranslations($request, $token);
    self::assertSame(0, $this->responseData($response)['keys_written']);
    self::assertSame(count($values), $this->responseData($response)['keys_skipped']);

    // A second active job exercises provider-driven abort and durable cleanup.
    [$aborted_job, $aborted_item] = $this->createJobItem($translator, 1002);
    $aborted_item->getData();
    $aborted_job->requestTranslation();
    $aborted_mappings = JobItem::load($aborted_item->id())->getRemoteMappings();
    $aborted_mapping = reset($aborted_mappings);
    $aborted_token = $aborted_mapping->getRemoteIdentifier1();
    $aborted_outbox_id = $outbox->idForSnapshot($aborted_token);

    self::assertTrue($aborted_job->abortTranslation());
    self::assertTrue(Job::load($aborted_job->id())->isAborted());
    self::assertTrue(JobItem::load($aborted_item->id())->isAborted());
    self::assertSame('cancelled', $snapshots->load($aborted_token)['status']);
    self::assertSame('cancelled', $outbox->load($aborted_outbox_id)['status']);
  }

  /**
   * Creates one configuration-backed Key entity.
   */
  private function createKey(string $id, string $value): void {
    $key = Key::create([
      'id' => $id,
      'label' => $id,
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_input' => 'none',
    ]);
    $key->setKeyValue($value);
    $key->save();
  }

  /**
   * Creates a saved TMGMT job and source item.
   *
   * @return array{\Drupal\tmgmt\JobInterface, \Drupal\tmgmt\JobItemInterface}
   *   The job and item.
   */
  private function createJobItem(Translator $translator, int $item_id): array {
    $job = tmgmt_job_create('en', 'de', 0, [
      'translator' => $translator->id(),
    ]);
    $job->save();
    $item = $job->addItem('test_source', 'paragraphs_fixture', $item_id);
    return [$job, $item];
  }

  /**
   * Builds a complete authenticated result request.
   *
   * @param string $token
   *   Opaque snapshot token.
   * @param array<string, string> $values
   *   Exact translations keyed by flattened TMGMT path.
   */
  private function translationRequest(string $token, array $values): Request {
    $request = Request::create(
      '/elan-bridge/v1/snapshots/' . $token . '/translations',
      'POST',
      [],
      [],
      [],
      [],
      json_encode([
        'locale' => 'de-DE',
        'values' => $values,
      ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    );
    $request->headers->set('Authorization', 'Bearer kernel-bearer-secret');
    return $request;
  }

  /**
   * Decodes a JSON response from the snapshot controller.
   *
   * @return array<string, mixed>
   *   Decoded response data.
   */
  private function responseData(JsonResponse $response): array {
    $data = json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertIsArray($data);
    return $data;
  }

  /**
   * Returns symmetric nested cached data shaped like two Paragraph items.
   *
   * @return array<string, mixed>
   *   A TMGMT source data tree.
   */
  private function paragraphSourceData(): array {
    return [
      'field_components' => [
        '#label' => 'Components',
        0 => [
          '#label' => 'Paragraph 1',
          'field_heading' => [
            '#label' => 'Heading',
            0 => [
              'value' => [
                '#text' => 'First heading',
                '#translate' => TRUE,
                '#max_length' => 80,
              ],
            ],
          ],
          'field_body' => [
            '#label' => 'Body',
            0 => [
              'value' => [
                '#text' => 'First paragraph body.',
                '#translate' => TRUE,
                '#format' => 'basic_html',
              ],
            ],
          ],
          'field_computed_hash' => [
            '#label' => 'Computed hash',
            0 => [
              'value' => [
                '#text' => 'paragraph-1-internal',
                '#translate' => FALSE,
              ],
            ],
          ],
        ],
        1 => [
          '#label' => 'Paragraph 2',
          'field_heading' => [
            '#label' => 'Heading',
            0 => [
              'value' => [
                '#text' => 'Second heading',
                '#translate' => TRUE,
                '#max_length' => 80,
              ],
            ],
          ],
          'field_body' => [
            '#label' => 'Body',
            0 => [
              'value' => [
                '#text' => 'Second paragraph body.',
                '#translate' => TRUE,
                '#format' => 'basic_html',
              ],
            ],
          ],
          'field_computed_hash' => [
            '#label' => 'Computed hash',
            0 => [
              'value' => [
                '#text' => 'paragraph-2-internal',
                '#translate' => FALSE,
              ],
            ],
          ],
        ],
      ],
    ];
  }

}
