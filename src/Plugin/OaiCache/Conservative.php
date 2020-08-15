<?php

namespace Drupal\rest_oai_pmh\Plugin\OaiCache;

use Drupal\rest_oai_pmh\Plugin\OaiCacheBase;

/**
 * Conservative cache clearing strategy.
 *   Only remove entities from the OAI cache when the entity is deleted.
 *
 * @OaiCache(
 *  id = "conservative_cache",
 *  label = @Translation("Conservative Cache Clearing Strategy")
 * )
 */
class Conservative extends OaiCacheBase {

  public function clearCache($entity, $op) {
    parent::clearCache($entity, $op);
  }

}
