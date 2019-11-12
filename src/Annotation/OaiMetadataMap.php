<?php

namespace Drupal\rest_oai_pmh\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a OAI Metadata Map item annotation object.
 *
 * @see \Drupal\rest_oai_pmh\Plugin\OaiMetadataMapManager
 * @see plugin_api
 *
 * @Annotation
 */
class OaiMetadataMap extends Plugin {


  /**
   * The plugin ID.
   *
   * @var string
   */
  public $id;

  /**
   * The label of the plugin.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $label;

  /**
   * The metadata format rendered.
   *
   * @var string
   */
  public $metadata_format;
}
