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
    rest_oai_pmh_cache_views();

    $queue = \Drupal::service('queue')->get('rest_oai_pmh_views_cache_cron');
    $operations = [];
    while ($item = $queue->claimItem()) {
      $operations[] = [
        'rest_oai_pmh_process_queue',
        [$item]
      ];
    }
    $batch = [
      'operations' => $operations,
      'finished' => 'rest_oai_pmh_batch_finished',
      'title' => $this->t('Processing OAI rebuild'),
      'init_message' => $this->t('OAI rebuild is starting.'),
      'progress_message' => $this->t('Processed @current out of @total.'),
      'error_message' => $this->t('OAI rebuild has encountered an error.'),
    ];

    batch_set($batch);
  }
}
