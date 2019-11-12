<?php

namespace Drupal\rest_oai_pmh\Plugin;

use Drupal\Component\Plugin\PluginBase;

/**
 * Base class for OAI Metadata Map plugins.
 */
abstract class OaiMetadataMapBase extends PluginBase implements OaiMetadataMapInterface
{

    /**
     * {@inheritdoc}
     */
    public function build($record)
    {
        $template = $this->getTemplatePath();
        return \Drupal::service('twig')
            ->loadTemplate($template)
            ->render($record);
    }

    /**
     * Method to return template file path.
     *
     * Stolen from https://git.drupalcode.org/project/dynamictagclouds/blob/8.x-dev/src/Plugin/TagCloudBase.php#L31-44.
     *
     * @return string
     *   Template file path.
     */
    protected function getTemplatePath()
    {
        $template = $this->getPluginDefinition()['template'];
        return drupal_get_path(
            $template['type'],
            $template['name']
        ) . '/' . $template['directory'] . '/' . $template['file'] . '.html.twig';
    }

}
