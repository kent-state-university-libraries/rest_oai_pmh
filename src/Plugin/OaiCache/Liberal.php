<?php

namespace Drupal\rest_oai_pmh\Plugin\OaiCache;

use Drupal\rest_oai_pmh\Plugin\OaiCacheBase;

/**
 * Liberal cache clearing strategy.
 *  Flush the cache when effected entities are added/updated/delete.
 *
 * @OaiCache(
 *  id = "liberal_cache",
 *  label = @Translation("Liberal Cache Clearing Strategy")
 * )
 */
class Liberal extends OaiCacheBase {

  public function clearCache($entity, $op) {
    $entity_type = $entity->getEntityTypeId();
    $entity_id = $entity->id();
    if ($op === 'delete') {
      parent::clearCache($entity, $op);
    }
    else {
      // If a View is being added/updated.
      if ($entity_type === 'view') {
        // Check if the View has a display that's exposed in OAI.
        $d_args = [
          ':view_id' => $entity_id . '%',
        ];
        $config = \Drupal::service('config.factory')->getEditable('rest_oai_pmh.settings');
        $oai_view_displays = $config->get('view_displays') ? : [];
        $in_config = FALSE;
        // Go through.
        foreach ($oai_view_displays as $view_display) {
          list($view_id, $display_id) = explode(':', $view_display);
          if ($view_id == $entity_id) {
            $in_config = TRUE;
            break;
          }
        }

        // If there is a display in OAI.
        if ($in_config) {
          $displays = [];
          foreach ($entity->get('display') as $display_id => $display) {
            $displays[] = $entity_id . ':' . $display_id;
          }
          $deleted_displays = array_diff($oai_view_displays, $displays);

          if (count($deleted_displays)) {
            foreach ($deleted_displays as $deleted_display) {
              rest_oai_pmh_remove_sets_by_display_id($deleted_display);
              unset($oai_view_displays[$deleted_display]);
            }
            $config->set('view_displays', $oai_view_displays)->save();
          }
          rest_oai_pmh_cache_views();
        }
      }
      else {
        // only rebuild cache if the entity type is exposed to OAI
        if (rest_oai_pmh_is_valid_entity_type($entity_type)) {
          $d_args = [
            ':entity_type' => $entity_type,
            ':entity_id' => $entity_id,
            ':set_id' => $entity_type . ':' . $entity_id,
          ];
          $rebuild = \Drupal::database()->query("SELECT * FROM {rest_oai_pmh_record} r, {rest_oai_pmh_set} s
            WHERE (s.entity_type = :entity_type AND s.set_id = :set_id)
              OR (r.entity_type = :entity_type AND r.entity_id = :entity_id)
  	        LIMIT 1", $d_args)->fetchField();
          if ($rebuild) {
            rest_oai_pmh_cache_views();
          }
        }
      }
    }
  }

}
