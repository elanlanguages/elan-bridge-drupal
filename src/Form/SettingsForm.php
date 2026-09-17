<?php

declare(strict_types=1);

namespace Drupal\elan_bridge\Form;

use Drupal\Core\Url;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\elan_bridge\Connection\ConnectionSettings;
use Drupal\elan_bridge\Event\EventOutbox;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the site-level Bridge connection.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * Constructs the settings form.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly EventOutbox $outbox,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('elan_bridge.event_outbox'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'elan_bridge_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [ConnectionSettings::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['setup'] = [
      '#type' => 'link',
      '#title' => $this->t('Connect automatically to ELAN demo'),
      '#url' => Url::fromRoute('elan_bridge.setup'),
    ];
    $config = $this->config(ConnectionSettings::CONFIG_NAME);
    $form['bridge_url'] = [
      '#type' => 'url',
      '#title' => $this->t('ELAN Bridge API base URL'),
      '#default_value' => $config->get('bridge_url'),
      '#description' => $this->t('The Bridge API origin and optional proxy prefix, without /connectors. HTTPS is required.'),
      '#required' => TRUE,
    ];
    $form['connection_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Connection ID'),
      '#default_value' => $config->get('connection_id'),
      '#description' => $this->t('The Bridge connection paired with this Drupal site.'),
      '#required' => TRUE,
    ];
    $form['bearer_key_id'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Bridge-to-Drupal bearer credential'),
      '#default_value' => $config->get('bearer_key_id'),
      '#description' => $this->t('A Key value Bridge presents when reading and writing TMGMT snapshots.'),
      '#required' => TRUE,
    ];
    $form['webhook_key_id'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Drupal-to-Bridge HMAC secret'),
      '#default_value' => $config->get('webhook_key_id'),
      '#description' => $this->t('A separate Key value used only to sign outbound events.'),
      '#required' => TRUE,
    ];

    $form = parent::buildForm($form, $form_state);
    $form['actions']['retry_failed'] = [
      '#type' => 'submit',
      '#value' => $this->t('Retry failed events'),
      '#submit' => ['::retryFailedEvents'],
      '#limit_validation_errors' => [],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $url = trim((string) $form_state->getValue('bridge_url'));
    if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
      $form_state->setErrorByName('bridge_url', $this->t('The ELAN Bridge URL must use HTTPS.'));
    }
    if (parse_url($url, PHP_URL_USER) !== NULL
      || parse_url($url, PHP_URL_QUERY) !== NULL
      || parse_url($url, PHP_URL_FRAGMENT) !== NULL) {
      $form_state->setErrorByName(
        'bridge_url',
        $this->t('The ELAN Bridge URL cannot contain credentials, a query, or a fragment.'),
      );
    }
    if ($form_state->getValue('bearer_key_id') === $form_state->getValue('webhook_key_id')) {
      $form_state->setErrorByName('webhook_key_id', $this->t('Use a different Key for HMAC event signing.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable(ConnectionSettings::CONFIG_NAME)
      ->set('bridge_url', rtrim(trim((string) $form_state->getValue('bridge_url')), '/'))
      ->set('connection_id', trim((string) $form_state->getValue('connection_id')))
      ->set('bearer_key_id', (string) $form_state->getValue('bearer_key_id'))
      ->set('webhook_key_id', (string) $form_state->getValue('webhook_key_id'))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Requeues terminal event failures after configuration is repaired.
   */
  public function retryFailedEvents(array &$form, FormStateInterface $form_state): void {
    $count = $this->outbox->retryDead();
    $this->messenger()->addStatus($this->formatPlural(
      $count,
      'One failed ELAN Bridge event was queued for retry.',
      '@count failed ELAN Bridge events were queued for retry.',
    ));
  }

}
