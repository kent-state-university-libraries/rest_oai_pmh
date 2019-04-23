<?php

namespace Drupal\rest_oai_pmh\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\ContentEntityType;
use Drupal\rest_oai_pmh\Plugin\rest\resource\OaiPmh;

/**
 * Class RestOaiPmhSettingsForm.
 */
class RestOaiPmhSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'rest_oai_pmh.settings',
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
    $config = $this->config('rest_oai_pmh.settings');

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
    $boolean_fields = ['- All collections exposed -'];
    foreach ($types as $type) {
      $bundle = $type->id();
      $bundles[$bundle] = $type->label();

      // get all the entity_reference fields for this bundle
      // @todo possibly support other field types?
      $field_definitions = \Drupal::service('entity_field.manager')
        ->getFieldDefinitions('node', $bundle);
      foreach ($field_definitions as $field_name => $field_definition) {
        if (!empty($field_definition->getTargetBundle())) {
          if ($field_definition->getType() === 'entity_reference' &&
            $field_definition->getFieldStorageDefinition()->getSetting('target_type') === 'node') {
            $fields[$field_name] = $field_name . ': ' . $field_definition->getLabel();
          }
          elseif ($field_definition->getType() === 'boolean') {
            $boolean_fields[$field_name] = $field_name . ': ' . $field_definition->getLabel();
          }
        }
      }

    }
    $form['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Bundle'),
      '#description' => $this->t('(Optional) bundle to expose to OAI-PMH. If a bundle is not selected, all nodes will be exposed to OAI-PMH'),
      '#options' => $bundles,
      '#default_value' => $config->get('bundle'),
    ];
    $form['set_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Set Field'),
      '#description' => $this->t('The entity reference field used to store set information.<br>If a node has a value in this field, it means the respective node is a member of the set the field references.'),
      '#options' => $fields,
      '#default_value' => $config->get('set_field'),
    ];
    $form['set_field_conditional'] = [
      '#type' => 'select',
      '#title' => $this->t('Set Field Conditional'),
      '#description' => $this->t('If you do not want all sets exposed to OAI-PMH, you select a boolean field that when set to "TRUE" the set will be exposed to OAI-PMH'),
      '#options' => $boolean_fields,
      '#default_value' => $config->get('set_field_conditional'),
    ];

    $name = $config->get('repository_name');
    if (!$name) {
      $name = \Drupal::config('system.site')->get('name');
    }
    $form['repository_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Repository Name'),
      '#default_value' => $name,
    ];

    $email = $config->get('repository_email');
    if (!$email) {
      $email = \Drupal::config('system.site')->get('mail');
    }
    $form['repository_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Repository Admin E-Mail'),
      '#default_value' => $email,
    ];


    $path = $config->get('repository_path');
    if (!$path) {
      $path = OaiPmh::OAI_DEFAULT_PATH;
    }
    $form['repository_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Repository Path'),
      '#default_value' => $path,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $submitted_path = $form_state->getValue('repository_path');
    $submitted_path = "/" . trim($submitted_path, "\r\n\t /");
    $old_path = $this->config('rest_oai_pmh.settings')->get('repository_path');
    // if they haven't set a path before, make it the default
    if (!$old_path) {
      $old_path = OaiPmh::OAI_DEFAULT_PATH;
    }

    // if the admin is changing the path
    // make sure the path they're changing it to doesn't already exist
    if ($submitted_path !== OaiPmh::OAI_DEFAULT_PATH &&
      \Drupal::service('path.validator')->getUrlIfValidWithoutAccessCheck($submitted_path)) {
      $form_state->setErrorByName('repository_path', $this->t('The path you attempted to change to already exists.'));
    }
    // if the admin changed the OAI endpoint path, invalidate cache and rebuild routes
    elseif ($submitted_path !== $old_path) {
      $this->updateRestEndpointPath($submitted_path);

      // check that the next path exists
      // if it doesn't, revert to what the path was before and throw an error
      if (!\Drupal::service('path.validator')->getUrlIfValidWithoutAccessCheck($submitted_path)) {
        $this->updateRestEndpointPath($old_path);
        $form_state->setErrorByName('repository_path', $this->t('Could not save the path you provided. Please check that the path does not contain any invalid characters.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $this->config('rest_oai_pmh.settings')
      ->set('entity_type', $form_state->getValue('entity_type'))
      ->set('bundle', $form_state->getValue('bundle'))
      ->set('set_field', $form_state->getValue('set_field'))
      ->set('set_field_conditional', $form_state->getValue('set_field_conditional'))
      ->set('repository_name', $form_state->getValue('repository_name'))
      ->set('repository_email', $form_state->getValue('repository_email'))
      ->save();
  }

  protected function updateRestEndpointPath($path) {
    $this->config('rest_oai_pmh.settings')
      ->set('repository_path', $path)
      ->save();

    // when updating the REST endpoint's path
    // the route path needs cleared to enable the new path
    \Drupal::getContainer()->get('cache.discovery')->delete('rest_plugins');
    \Drupal::service('router.builder')->rebuild();
  }
}
