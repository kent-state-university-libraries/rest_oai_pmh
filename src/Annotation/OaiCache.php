<?php

namespace Drupal\rest_oai_pmh\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a OAI Cache annotation object.
 *
 * @see \Drupal\rest_oai_pmh\Plugin\OaiCacheManager
 * @see plugin_api
 *
 * @Annotation
 */
class OaiCache extends Plugin
{


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
}
