<?php

namespace Drupal\rest_oai_pmh\Plugin;

use Drupal\Component\Plugin\PluginBase;

/**
 * Base class for OAI Cache plugins.
 */
abstract class OaiCacheBase extends PluginBase implements OaiCacheInterface {

  public function clearCache($entity, $op) {
    if ($op === 'delete') {
      $entity_type = $entity->getEntityTypeId();
      $entity_id = $entity->id();
      // If a View is being deleted.
      if ($entity_type === 'view') {
        // Check if there are any sets in OAI with a display from this View.
        $d_args = [':view_id' => $entity_id . '%'];
        $view_displays = \Drupal::database()->query(
              "SELECT DISTINCT(view_display) FROM {rest_oai_pmh_set} s
          WHERE s.view_display LIKE :view_id", $d_args
          )->fetchCol();
        // For any set found, delete it.
        foreach ($view_displays as $view_display) {
          rest_oai_pmh_remove_sets_by_display_id($view_display);
        }
      }
      // For any other entity, delete all sets/records for the entity.
      else {
        rest_oai_pmh_remove_record($entity_type, $entity_id);
      }
    }
  }

}
