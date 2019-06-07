<?php

namespace Drupal\rest_oai_pmh\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\ContentEntityType;
use Drupal\rest_oai_pmh\Plugin\rest\resource\OaiPmh;
use Drupal\views\Views;

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

    $form['data'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('What to expose to OAI-PMH'),
      '#description' => $this->t('<p>Select which Views with an Entity Reference display will be exposed to OAI-PMH.</p>
        <p>Each View will be represented as a set in the OAI-PMH endpoint, except for those Views that contain a contextual filter to an entity reference. If the View has one of these contextual filters, the possible values in the referenced field will be used as the sets.</p>'),
    ];


    $displays = Views::getApplicableViews('entity_reference_display');
    $view_storage = \Drupal::entityTypeManager()->getStorage('view');
    $view_displays = [];
    foreach ($displays as $data) {
      list($view_id, $display_id) = $data;
      $view = $view_storage->load($view_id);
      $display = $view->get('display');
      $set_name = $view_id . ':' . $display_id;
      $view_displays[$set_name] = $display[$display_id]['display_title'] . ' (' . $set_name . ')';
    }

    $form['data']['view_displays'] = [
      '#type' => 'checkboxes',
      '#options' => $view_displays,
      '#default_value' => $config->get('view_displays') ? : [],
    ];

    $name = $config->get('repository_name');
    $form['repository_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Repository Name'),
      '#default_value' => $name ? : \Drupal::config('system.site')->get('name'),
      '#required' => TRUE,
    ];

    $email = $config->get('repository_email');
    $form['repository_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Repository Admin E-Mail'),
      '#default_value' => $email ? : \Drupal::config('system.site')->get('mail'),
      '#required' => TRUE,
    ];


    $path = $config->get('repository_path');
    $form['repository_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Repository Path'),
      '#default_value' => $path ? : OaiPmh::OAI_DEFAULT_PATH,
      '#required' => TRUE,
    ];

    $expiration = $config->get('expiration');
    $form['expiration'] = [
      '#type' => 'number',
      '#title' => $this->t('The number of seconds until a resumption token expires'),
      '#default_value' => $expiration ? : 3600,
      '#required' => TRUE,
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

    $earliest_date = \Drupal::time()->getRequestTime();

    $this->config('rest_oai_pmh.settings')
      ->set('view_displays', $form_state->getValue('view_displays'))
      ->set('repository_name', $form_state->getValue('repository_name'))
      ->set('repository_email', $form_state->getValue('repository_email'))
      ->set('expiration', $form_state->getValue('expiration'))
      ->set('earliest_date', $earliest_date);
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
