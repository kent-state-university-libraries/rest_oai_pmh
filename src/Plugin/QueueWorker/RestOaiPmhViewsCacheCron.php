<?php

namespace Drupal\rest_oai_pmh\Plugin\QueueWorker;

/**
 * @QueueWorker(
 *   id = "rest_oai_pmh_views_cache_cron",
 *   title = @Translation("REST OAI-PMH Views Cache"),
 *   cron = {"time" = 60}
 * )
 */
class RestOaiPmhViewsCacheCron extends RestOaiPmhViewsCacheBase {}