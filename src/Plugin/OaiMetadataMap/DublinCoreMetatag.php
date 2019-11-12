<?php

namespace Drupal\rest_oai_pmh\Plugin\OaiMetadataMap;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Dublin Core using Metatag.
 *
 * @OaiMetadataMap(
 *  id = "dublin_core_metatag",
 *  label = @Translation("OAI Dublin Core (Metatag)"),
 *  metadata_format = "oai_dc",
 *  template = {
 *    "type" = "module",
 *    "name" = "rest_oai_pmh",
 *    "directory" = "templates",
 *    "file" = "oai-default"
 *  }
 * )
 */
class DublinCoreMetatag extends DublinCoreRdf {

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
    if (!\Drupal::moduleHandler()->moduleExists('metatag_dc')) {
      \Drupal::logger('rest_oai_pmh')->warning(
        $this->t("Can't use Metatag-based Dublin Core without enabling Metatag!")
      );
      return '';
    }
    $render_array['metadata_prefix'] = 'oai_dc';
    $allowed_properties = $this->get_allowed_properties('oai_dc');
    $metatags = metatag_generate_entity_metatags($entity);
    // Go through all the metatags ['#type' => 'tag'] render elements.
    foreach ($metatags as $term => $metatag) {
      if (empty($metatag['#attributes']['name'])) {
        continue;
      }

      // metatag_dc stores terms ad dcterms.ELEMENT
      // rename for oai_dc.
      $property = str_replace('dcterms.', 'dc:', $metatag['#attributes']['name']);
      // See if it's a valid property.
      if (in_array($property, $allowed_properties)) {
        // Add all the values for this field so the twig template can print.
        $render_array['elements'][$property][] = $metatag['#attributes']['content'];
      }
    }

    return parent::build($render_array);
  }

}
