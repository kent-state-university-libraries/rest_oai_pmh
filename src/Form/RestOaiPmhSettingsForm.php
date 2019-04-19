<?php

namespace Drupal\rest_oai_pmh\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\ContentEntityType;

/**
 * Class RestOaiPmhSettingsForm.
 */
class RestOaiPmhSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'rest_oai_pmh.restoaipmhsettings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rest_oai_pmh_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('rest_oai_pmh.restoaipmhsettings');

    $form['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity Type'),
      '#description' => $this->t('The entity type to expose to OAI-PMH'),
      '#options' => ['node' => $this->t('Node')],
      '#required' => TRUE,
      '#default_value' => $config->get('entity_type') ? $config->get('entity_type') : 'node',
    ];

    $types = \Drupal::entityTypeManager()
      ->getStorage('node_type')
      ->loadMultiple();
    $bundles = ['- All bundles -'];
    $fields = ['- No sets -'];
    foreach ($types as $type) {
      $bundle = $type->id();
      $bundles[$bundle] = $type->label();

      // get all the entity_reference fields for this bundle
      // @todo possibly support other field types?
      $field_definitions = \Drupal::service('entity_field.manager')
        ->getFieldDefinitions('node', $bundle);
      foreach ($field_definitions as $field_name => $field_definition) {
        if (!empty($field_definition->getTargetBundle()) &&
          $field_definition->getType() === 'entity_reference') {
          $fields[$field_name] = $field_name . ': ' . $field_definition->getLabel();
        }
      }

    }
    $form['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Bundle'),
      '#description' => $this->t('(Optional) bundle to expose to OAI-PMH'),
      '#options' => $bundles,
      '#default_value' => $config->get('bundle'),
    ];
    $form['set_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Set Field'),
      '#description' => $this->t('The field used to store set information'),
      '#options' => $fields,
      '#default_value' => $config->get('set_field'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $this->config('rest_oai_pmh.restoaipmhsettings')
      ->set('entity_type', $form_state->getValue('entity_type'))
      ->set('bundle', $form_state->getValue('bundle'))
      ->set('set_field', $form_state->getValue('set_field'))
      ->save();
  }

}
