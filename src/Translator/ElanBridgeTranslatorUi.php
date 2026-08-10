<?php

declare(strict_types=1);

namespace Drupal\elan_bridge\Translator;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\tmgmt\TranslatorPluginUiBase;

/**
 * TMGMT configuration UI for one Bridge project binding.
 */
final class ElanBridgeTranslatorUi extends TranslatorPluginUiBase {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();
    $form['binding_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Bridge project binding ID'),
      '#default_value' => $translator->getSetting('binding_id'),
      '#min' => 1,
      '#required' => TRUE,
      '#description' => $this->t('The exact project/trigger binding selected for this TMGMT translator. Configure the site connection at <a href=":url">ELAN AI Bridge settings</a>.', [
        ':url' => Url::fromRoute('elan_bridge.settings')->toString(),
      ]),
    ];
    $form['review_policy'] = [
      '#type' => 'item',
      '#title' => $this->t('Review policy'),
      '#markup' => $this->t('Automatic acceptance must remain disabled. ELAN AI Bridge returns completed translations to TMGMT Needs review.'),
    ];
    return $form;
  }

}
