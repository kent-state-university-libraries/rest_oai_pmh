<?php

namespace Drupal\rest_oai_pmh\Plugin\OaiMetadataMap;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\rest_oai_pmh\Plugin\OaiMetadataMapBase;

/**
 * Default Metadata Map.
 *
 * @OaiMetadataMap(
 *  id = "dublin_core_rdf",
 *  label = @Translation("OAI Dublin Core (RDF Mapping)"),
 *  metadata_format = "oai_dc",
 *  template = {
 *    "type" = "module",
 *    "name" = "rest_oai_pmh",
 *    "directory" = "templates",
 *    "file" = "oai-default"
 *  }
 * )
 */
class DublinCoreRdf extends OaiMetadataMapBase {

  /**
   *
   */
  public function getMetadataFormat() {
    return [
      'metadataPrefix' => 'oai_dc',
      'schema' => 'http://www.openarchives.org/OAI/2.0/oai_dc.xsd',
      'metadataNamespace' => 'http://www.openarchives.org/OAI/2.0/oai_dc/',
    ];
  }

  /**
   *
   */
  public function getMetadataWrapper() {
    return [
      'oai_dc' => [
        '@xmlns:dc' => 'http://purl.org/dc/elements/1.1/',
        '@xmlns:oai_dc' => 'http://www.openarchives.org/OAI/2.0/oai_dc/',
        '@xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
        '@xsi:schemaLocation' => 'http://www.openarchives.org/OAI/2.0/oai_dc/ http://www.openarchives.org/OAI/2.0/oai_dc.xsd',
      ],
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
    if (!\Drupal::moduleHandler()->moduleExists('rdf')) {
      \Drupal::logger('rest_oai_pmh')->warning(
        $this->t("Can't use RDF Mapping-based Dublin Core without the RDF module enabled!")
      );
      return '';
    }
    $render_array['metadata_prefix'] = 'oai_dc';
    $rdf_mapping = rdf_get_mapping($entity->getEntityTypeId(), $entity->bundle());
    $allowed_properties = $this->get_allowed_properties('oai_dc');
    foreach ($entity->getFields() as $field_id => $fieldItemList) {
      if (!$fieldItemList->access() || $fieldItemList->isEmpty()) {
        continue;
      }
      $field_mapping = $rdf_mapping->getPreparedFieldMapping($field_id);
      $element = FALSE;
      if (!empty($field_mapping)) {
        // See if the field is mapped to a property in this schema
        // e.g oai_dc only will print Dublin Core tags.
        foreach ($field_mapping['properties'] as $property) {
          // DC /elements/1.1 may be prefixed with dc11
          // and dcterms may be prefixed accordingly
          // so just transform the property value to a standard namespace (dc)
          // to easily map properties to their respective
          // http://purl.org/dc/elements/1.1/ value
          $property_components = explode(':', $property);
          if (isset($property_components[0]) &&
            in_array($property_components[0], ['dc11', 'dcterms'])) {
            $property_components[0] = 'dc';
            $property = implode(':', $property_components);
          }

          // If this is a DC /elements/1.1, set the element.
          if (in_array($property, $allowed_properties)) {
            $element = $property;
          }
          // DC /terms/ are mapped to their respective /elements/1.1
          // in accordance with the oai_dc schema http://www.openarchives.org/OAI/2.0/oai_dc.xsd
          elseif (isset($allowed_properties[$property])) {
            $element = $allowed_properties[$property];
          }
          if ($element) {
            break;
          }
        }
      }
      // If $element is set, we have a valid property.
      if ($element) {
        // Add all the values for this field so the twig template can print.
        foreach ($fieldItemList as $item) {
          $index = $item->mainPropertyName();
          if (!empty($field_mapping['datatype_callback'])) {
            $callback = $field_mapping['datatype_callback']['callable'];
            $arguments = isset($field_mapping['datatype_callback']['arguments']) ? $field_mapping['datatype_callback']['arguments'] : NULL;
            $data = $item->getValue();
            $value = call_user_func($callback, $data, $arguments);
          }
          elseif ($index == 'target_id' && !empty($item->entity)) {
            $value = $item->entity->label();
          }
          else {
            $value = $item->getValue()[$index];
          }
          $render_array['elements'][$element][] = $value;
        }
      }

    }
    return parent::build($render_array);
  }

  /**
   * Helper function.
   *
   * Return what properties we're looking for in metadata mapping modules given an OAI metadata prefix.
   */
  public function get_allowed_properties($metadata_prefix) {
    $elements = [];
    switch ($metadata_prefix) {
      case 'oai_dc':
        $elements = [
          'dc:contributor',
          'dc:coverage',
          'dc:creator',
          'dc:date',
          'dc:description',
          'dc:format',
          'dc:identifier',
          'dc:language',
          'dc:publisher',
          'dc:relation',
          'dc:rights',
          'dc:source',
          'dc:subject',
          'dc:title',
          'dc:type',

          // Plus http://purl.org/dc/terms
          // mapped to their respective http://purl.org/dc/elements/1.1/
          'dc:abstract' => 'dc:description',
          'dc:accessRights' => 'dc:rights',
          'dc:alternative' => 'dc:title',
          'dc:available' => 'dc:date',
          'dc:bibliographicCitation' => 'dc:identifier',
          'dc:conformsTo' => 'dc:relation',
          'dc:created' => 'dc:date',
          'dc:dateAccepted' => 'dc:date',
          'dc:dateCopyrighted' => 'dc:date',
          'dc:dateSubmitted' => 'dc:date',
          'dc:educationLevel' => 'dc:audience',
          'dc:extent' => 'dc:format',
          'dc:hasFormat' => 'dc:format',
          'dc:hasPart' => 'dc:relation',
          'dc:hasVersion' => 'dc:relation',
          'dc:isFormatOf' => 'dc:relation',
          'dc:isPartOf' => 'dc:relation',
          'dc:isReferencedBy' => 'dc:relation',
          'dc:isReplacedBy' => 'dc:relation',
          'dc:isRequiredBy' => 'dc:relation',
          'dc:isVersionOf' => 'dc:relation',
          'dc:issued' => 'dc:date',
          'dc:license' => 'dc:rights',
          'dc:mediator' => 'dc:audience',
          'dc:medium' => 'dc:format',
          'dc:modified' => 'dc:date',
          'dc:references' => 'dc:relation',
          'dc:replaces' => 'dc:relation',
          'dc:requires' => 'dc:relation',
          'dc:spatial' => 'dc:coverage',
          'dc:tableOfContents' => 'dc:description',
          'dc:temporal' => 'dc:coverage',
          'dc:valid' => 'dc:date',
        ];
        break;
    }

    return $elements;
  }

}
