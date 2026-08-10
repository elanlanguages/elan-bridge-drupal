<?php

declare(strict_types=1);

namespace Drupal\elan_bridge\Plugin\tmgmt\Translator;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\elan_bridge\Connection\ConnectionSettings;
use Drupal\elan_bridge\Submission\SubmissionManager;
use Drupal\elan_bridge\Translator\ElanBridgeTranslatorUi;
use Drupal\tmgmt\Attribute\TranslatorPlugin;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\Translator\AvailableResult;
use Drupal\tmgmt\Translator\TranslatableResult;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\tmgmt\TranslatorPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Submits TMGMT jobs to the ELAN AI Bridge.
 */
#[TranslatorPlugin(
  id: 'elan_bridge',
  label: new TranslatableMarkup('ELAN AI Bridge'),
  description: new TranslatableMarkup(
    'Submits translation jobs to an exact ELAN AI Bridge project binding.',
  ),
  default_settings: [
    'binding_id' => 0,
  ],
  ui: ElanBridgeTranslatorUi::class,
  files: FALSE,
  map_remote_languages: TRUE,
)]
final class ElanBridgeTranslator extends TranslatorPluginBase implements ContainerFactoryPluginInterface {

  private const TRANSLATOR_STATE_QUEUED = 'elan_bridge_queued';

  /**
   * Constructs the ELAN AI Bridge translator.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ConnectionSettings $connectionSettings,
    private readonly SubmissionManager $submissionManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('elan_bridge.connection_settings'),
      $container->get('elan_bridge.submission_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function checkAvailable(TranslatorInterface $translator) {
    if (!$this->connectionSettings->isConfigured()) {
      return AvailableResult::no(new TranslatableMarkup(
        'Configure the ELAN AI Bridge connection before using this provider.',
      ));
    }
    if ((int) $translator->getSetting('binding_id') < 1) {
      return AvailableResult::no(new TranslatableMarkup(
        'Select an ELAN AI Bridge project binding before using this provider.',
      ));
    }
    return AvailableResult::yes();
  }

  /**
   * {@inheritdoc}
   */
  public function checkTranslatable(
    TranslatorInterface $translator,
    JobInterface $job,
  ) {
    if ($translator->isAutoAccept()) {
      return TranslatableResult::no(new TranslatableMarkup(
        'Disable automatic acceptance; ELAN AI Bridge translations must stop for TMGMT review.',
      ));
    }
    return parent::checkTranslatable($translator, $job);
  }

  /**
   * {@inheritdoc}
   */
  public function requestTranslation(JobInterface $job) {
    $translator = $job->getTranslator();
    if ($translator->isAutoAccept()) {
      $job->rejected(
        'Automatic acceptance is not supported; ELAN AI Bridge translations must stop for TMGMT review.',
      );
      return;
    }

    $binding_id = (int) $translator->getSetting('binding_id');

    // SubmissionManager commits the snapshots and event outbox before this
    // method changes any TMGMT state. Its exception deliberately leaves the
    // job unprocessed so the request can be retried safely.
    $this->submissionManager->submitJob($job, $binding_id);

    $job->submitted('The translation job has been queued for ELAN AI Bridge.');
    foreach ($job->getItems() as $item) {
      $item->setTranslatorState(self::TRANSLATOR_STATE_QUEUED);
      $item->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function abortTranslation(JobInterface $job) {
    $this->submissionManager->abortJob($job);
    $job->aborted('The ELAN AI Bridge translation job has been aborted.');
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function hasCheckoutSettings(JobInterface $job) {
    return FALSE;
  }

}
