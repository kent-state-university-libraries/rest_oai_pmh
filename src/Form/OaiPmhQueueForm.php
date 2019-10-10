<?php

namespace Drupal\rest_oai_pmh\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Queue\QueueWorkerInterface;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

class OaiPmhQueueForm extends FormBase {

  /**
   * @var QueueFactory
   */
  protected $queueFactory;

  /**
   * @var QueueWorkerManagerInterface
   */
  protected $queueManager;


  /**
   * {@inheritdoc}
   */
  public function __construct(QueueFactory $queue, QueueWorkerManagerInterface $queue_manager) {
    $this->queueFactory = $queue;
    $this->queueManager = $queue_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('queue'),
      $container->get('plugin.manager.queue_worker')
    );
  }

  /**
   * {@inheritdoc}.
   */
  public function getFormId() {
    return 'oai_pmh_queue_form';
  }

  /**
   * {@inheritdoc}.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['info'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Submitting this form will rebuild your OAI-PMH entries.<br>This will automatically be done on cron, but you can perform it manually here.'),
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Rebuild OAI-PMH'),
      '#button_type' => 'primary',
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    rest_oai_pmh_rebuild_entries();

    // if no more items exist in the queue (we broke the while loop)
    // print a success message, linking to the OAI endpoint
    if (!$item) {
      $url_options = [
        'absolute' => TRUE,
        'query' => [
          'verb' => 'ListRecords',
          'metadataPrefix' => 'oai_dc',
        ]
      ];
      $t_args = [
        ':link' => Url::fromRoute('rest.oai_pmh.GET', [], $url_options)->toString()
      ];
      drupal_set_message($this->t('Successfully rebuilt your OAI-PMH entries. You can now see your records at <a href=":link">:link</a>', $t_args));
    }
    else {
     $url_options = [
        'absolute' => TRUE,
      ];
      $t_args = [
        ':link' => Url::fromRoute('dblog.overview', [], $url_options)->toString()
      ];
      drupal_set_message($this->t('Could not rebuild your OAI-PMH endpoint. Please check your <a href=":link">Recent log messages</a>', $t_args), 'error');
    }
  }
}
