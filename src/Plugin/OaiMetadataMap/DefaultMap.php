<?php

namespace Drupal\rest_oai_pmh\Plugin\OaiMetadataMap;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\rest_oai_pmh\Plugin\OaiMetadataMapBase;

/**
 * Default Metadata Map.
 *
 * @OaiMetadataMap(
 *  id = "default_metadata_map",
 *  label = @Translation("Raw Fields (use for testing only)"),
 *  metadata_format = "oai_raw",
 *  template = {
 *    "type" = "module",
 *    "name" = "rest_oai_pmh",
 *    "directory" = "templates",
 *    "file" = "oai-default"
 *  }
 * )
 */
class DefaultMap extends OaiMetadataMapBase {

  /**
   *
   */
  public function getMetadataFormat() {
    return [
      'metadataPrefix' => 'oai_raw',
      'schema' => '',
      'metadataNamespace' => '',
    ];

  }

  /**
   *
   */
  public function getMetadataWrapper() {

    return [
      'oai_raw' => [],
    ];
  }

  /**
   * Method to transform the provided entity into the desired metadata record.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   the entity to transform.
   *
   * @return string
   *   rendered XML.
   */
  public function transformRecord(ContentEntityInterface $entity) {
    $render_array['metadata_prefix'] = 'oai_raw';
    foreach ($entity->getFields() as $field_id => $fieldItemList) {
      if (!$fieldItemList->access() || $fieldItemList->isEmpty()) {
        continue;
      }
      foreach ($fieldItemList as $item) {
        $index = $item->mainPropertyName();
        if ($index == 'target_id' && !empty($item->entity)) {
          $value = $item->entity->label();
        }
        else {
          $value = $item->getValue()[$index];
        }
        $render_array['elements'][$field_id][] = $value;
      }
    }
    return parent::build($render_array);
  }

}
