<?php

namespace Drupal\rest_oai_pmh\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    if ($route = $collection->get('rest.oai_pmh.POST')) {
      $route->setRequirement('_content_type_format', 'form');
    }
  }

}
