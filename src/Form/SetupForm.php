<?php

declare(strict_types=1);

namespace Drupal\elan_bridge\Form;

use Drupal\Core\State\StateInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\elan_bridge\Setup\SetupManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides self-service connection to the hosted ELAN demo.
 */
final class SetupForm extends FormBase {

  /**
   * Constructs the setup form.
   */
  public function __construct(private readonly SetupManager $setup, private readonly StateInterface $siteState) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('elan_bridge.setup_manager'), $container->get('state'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'elan_bridge_setup';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $state = $this->siteState;
    $connection = $this->config('elan_bridge.settings')->get('connection_id');
    $saved = $state->get('elan_bridge.setup', []);
    $request = $this->getRequest();
    $form['#cache']['max-age'] = 0;
    $form['intro'] = ['#markup' => '<p>' . $this->t('Connect this Drupal site to demo.elanlanguages.ai. Sign in to ELAN, select your organization and translation project, then return here. Credentials and language mappings are configured automatically.') . '</p>'];
    if ($connection) {
      $form['status'] = [
        '#type' => 'item',
        '#title' => $this->t('Connection'),
        '#plain_text' => ($saved['organization'] ?? '') . ' / ' . ($saved['project_name'] ?? $this->t('Existing ELAN connection')),
      ];
    }
    $cron = (int) $state->get('system.cron_last', 0);
    $form['cron'] = [
      '#type' => 'item',
      '#title' => $this->t('Background processing'),
      '#markup' => $cron && time() - $cron < 3600
        ? $this->t('Drupal cron has run within the last hour. Keep it scheduled to deliver translation requests.')
        : $this->t('No recent Drupal cron run was found. Schedule Drupal cron before relying on unattended translation delivery.'),
    ];
    $form['site_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Public Drupal URL'),
      '#required' => TRUE,
      '#default_value' => $saved['site_url'] ?? $request->getSchemeAndHttpHost() . $request->getBasePath(),
      '#description' => $this->t('Detected from this installation. Override it only if ELAN must use a different public HTTPS address, such as a development tunnel. Include any Drupal subdirectory.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['connect'] = [
      '#type' => 'submit',
      '#value' => $connection ? $this->t('Reconnect to ELAN demo') : $this->t('Connect to ELAN demo'),
      '#button_type' => 'primary',
    ];
    if ($connection) {
      $form['disconnect_notice'] = ['#markup' => '<p>' . $this->t('Disconnect revokes translation access for this site. Existing review results and job history remain available.') . '</p>'];
      $form['actions']['disconnect'] = [
        '#type' => 'submit',
        '#value' => $this->t('Disconnect'),
        '#submit' => ['::disconnectSubmit'],
        '#limit_validation_errors' => [],
      ];
    }
    $form['advanced'] = [
      '#type' => 'link',
      '#title' => $this->t('Advanced connection settings'),
      '#url' => Url::fromRoute('elan_bridge.settings'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      SetupManager::normalizeUrl((string) $form_state->getValue('site_url'));
    }
    catch (\InvalidArgumentException $error) {
      $form_state->setErrorByName('site_url', $error->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $request = $this->getRequest();
      $url = $this->setup->start((string) $form_state->getValue('site_url'), $request->getSchemeAndHttpHost() . $request->getBasePath());
      $form_state->setResponse(new TrustedRedirectResponse($url));
    }
    catch (\Throwable $error) {
      $this->messenger()->addError($error->getMessage());
    }
  }

  /**
   * Disconnects without deleting any TMGMT entities.
   */
  public function disconnectSubmit(array &$form, FormStateInterface $form_state): void {
    try {
      $this->setup->disconnect();
      $this->messenger()->addStatus($this->t('ELAN has been disconnected. You can reconnect from this page.'));
      $form_state->setRedirect('elan_bridge.setup');
    }
    catch (\Throwable $error) {
      $this->messenger()->addError($error->getMessage());
    }
  }

}
