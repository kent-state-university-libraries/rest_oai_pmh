<?php

namespace Drupal\rest_oai_pmh\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\views\Views;

abstract class RestOaiPmhViewsCacheBase extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  protected $db;

  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->db = \Drupal::database();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $this->view_id = $data['view_id'];
    $this->display_id = $data['display_id'];
    foreach ($data['sets'] as $data) {
      $this->arguments = $data['arguments'];
      $this->set_id = $data['set_id'];
      $set_entity_type = $data['set_entity_type'];
      $set_label = $data['set_label'];
      $view_display = $data['view_display'];

      $view = Views::getView($this->view_id);
      $view->setDisplay($this->display_id);
      $view->get_total_rows = TRUE;
      $members = $view->executeDisplay($this->display_id, $this->arguments);
      $limit =  $view->getItemsPerPage();
      $total = $view->total_rows;
      if ($total > 0) {
        $merge_keys = [
          'entity_type',
          'set_id'
        ];
        $merge_values = [
          $set_entity_type,
          $this->set_id,
        ];
        $this->db->merge('rest_oai_pmh_set')
          ->keys($merge_keys, $merge_values)
          ->fields([
            'label' => $set_label,
            'limit' => $limit,
            'view_display' => $view_display
          ])->execute();

        $this->member_entity_type = $view->getBaseEntityType()->id();
        $this->member_entity_storage = \Drupal::entityTypeManager()->getStorage($this->member_entity_type);

        $this->indexViewRecords($members);

        // if there are more records than what was returned by the first View execution
        // page through the View to get all the records
        $this->offset = $limit;
        while ($limit < $total) {
          $this->indexViewRecords();
          $this->offset += $limit;
          $total -= $limit;
        }
      }
      else {
        rest_oai_pmh_remove_set($this->set_id);
      }
    }
  }

  protected function indexViewRecords($members = FALSE) {
    if (!$members) {
      $view = Views::getView($this->view_id);
      $view->setDisplay($this->display_id);
      $view->setOffset($this->offset);
      $members = $view->executeDisplay($this->display_id, $this->arguments);
    }

    foreach ($members as $id => $row) {
      $merge_keys = [
        'entity_type',
        'entity_id'
      ];
      $merge_values = [
        $this->member_entity_type,
        $id
      ];
      $entity = $this->member_entity_storage->load($id);
      if ($entity) {
        // get the changed/created values, if they exist
        $changed = $entity->hasField('changed') ? $entity->changed->value : \Drupal::time()->requestTime();
        $created = $entity->hasField('created') ? $entity->created->value : $changed;
        // upsert the record into our cache table
        $this->db->merge('rest_oai_pmh_record')
          ->keys($merge_keys, $merge_values)
          ->fields([
            'created' => $created,
            'changed' => $changed,
          ])->execute();

        // add this record to the respective set
        $merge_keys[] = 'set_id';
        $merge_values[] = $this->set_id;
        $this->db->merge('rest_oai_pmh_member')
          ->keys($merge_keys, $merge_values)
          ->execute();
      }
    }
  }
}
