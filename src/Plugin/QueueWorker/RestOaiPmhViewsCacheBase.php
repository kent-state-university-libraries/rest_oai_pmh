<?php

namespace Drupal\rest_oai_pmh\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\views\Views;

abstract class RestOaiPmhViewsCacheBase extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  protected $db;
  protected $view_id, $display_id, $arguments, $set_id, $member_entity_type, $member_entity_storage;

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
    $view_id = $data['view_id'];
    $display_id = $data['display_id'];
    $offset = isset($data['offset']) ? $data['offset'] : NULL;
    $limit = $data['limit'];
    $arguments = $data['arguments'];
    $this->set_id = $data['set_id'];
    $set_entity_type = $data['set_entity_type'];
    $set_label = $data['set_label'];
    $view_display = $data['view_display'];

    // load the View and apply the display ID
    $view = Views::getView($view_id);
    $view->setDisplay($display_id);
    if (!is_null($offset)) {
      $view->setOffset($offset);
    }

    // make sure we fetch the total results on the first execution so we can page through all the results
    $view->get_total_rows = TRUE;
    // get the first set of results from the View
    $members = $view->executeDisplay($display_id, $arguments);
    // after we executed the View, we'll know how many items were returned
    // use this to page through all results
    $total = $view->total_rows;
    // if some results were returned, save them to our rest_oai_pmh_* tables
    if ($total > 0) {
      // init the variables used for the UPSERT database call to add/update this SET
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
          'pager_limit' => $limit,
          'view_display' => $view_display
        ])->execute();

      // see what type of entity was returned by the View and set variable accordingly so we can load the entity
      $this->member_entity_type = $view->getBaseEntityType()->id();
      $this->member_entity_storage = \Drupal::entityTypeManager()->getStorage($this->member_entity_type);

      // add the results returned to {rest_oai_pmh_record} + {rest_oai_pmh_member}
      $this->indexViewRecords($members);

      // @todo track records that existed BEFORE we indexed the sets, and remove any records that once belonged to the set but might no longer belong
    }
    // if no results were returned, make sure this set is removed from our tables
    // so it won't be exposed to OAI-PMH
    else {
      rest_oai_pmh_remove_set($this->set_id);
    }
  }

  /**
   * Helper function. Add items returned by a view to {rest_oai_pmh_record} + {rest_oai_pmh_member}
   */
  protected function indexViewRecords($members = FALSE) {
    foreach ($members as $id => $row) {
      // init the variables used for the UPSERT database call to add/update this RECORD
      $merge_keys = [
        'entity_type',
        'entity_id'
      ];
      $merge_values = [
        $this->member_entity_type,
        $id
      ];
      // load the entity, partly to ensure it exists, also to get the changed/created properties
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
