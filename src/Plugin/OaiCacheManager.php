<?php

namespace Drupal\rest_oai_pmh\Plugin;

use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Provides the OAI Cache plugin manager.
 */
class OaiCacheManager extends DefaultPluginManager
{

    /**
     * Constructs a new OaiMetadataMapManager object.
     *
     * @param \Traversable                                  $namespaces
     *   An object that implements \Traversable which contains the root paths
     *   keyed by the corresponding namespace to look for plugin implementations.
     * @param \Drupal\Core\Cache\CacheBackendInterface      $cache_backend
     *   Cache backend instance to use.
     * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
     *   The module handler to invoke the alter hook with.
     */
    public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler)
    {
        parent::__construct(
            'Plugin/OaiCache',
            $namespaces,
            $module_handler,
            'Drupal\rest_oai_pmh\Plugin\OaiCacheInterface',
            'Drupal\rest_oai_pmh\Annotation\OaiCache'
        );

        $this->alterInfo('rest_oai_pmh_oai_cache_info');
        $this->setCacheBackend($cache_backend, 'rest_oai_pmh_oai_cache_plugins');
    }
}
