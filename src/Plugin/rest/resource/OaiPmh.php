<?php

namespace Drupal\rest_oai_pmh\Plugin\rest\resource;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides a resource to get view modes by entity and bundle.
 *
 * @RestResource(
 *   id = "oai_pmh",
 *   label = @Translation("OAI-PMH"),
 *   uri_paths = {
 *     "canonical" = "/oai/request"
 *   }
 * )
 */
class OaiPmh extends ResourceBase {

  /**
   * A current user instance.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;
  protected $currentRequest;
  private $response = [];

  const OAI_DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

  /**
   * Constructs a new OaiPmh object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   A current user instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    AccountProxyInterface $current_user,
    Request $currentRequest) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);

    $this->currentUser = $current_user;
    $this->currentRequest = $currentRequest;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest_oai_pmh'),
      $container->get('current_user'),
      $container->get('request_stack')->getCurrentRequest()
    );
  }

  /**
   * Responds to GET requests.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function get() {

    // You must to implement the logic of your REST Resource here.
    // Use current user after pass authentication to validate access.
    if (!$this->currentUser->hasPermission('access content')) {
      throw new AccessDeniedHttpException();
    }

    $base_oai_url = $this->currentRequest->getSchemeAndHttpHost() . '/oai/request';

    $this->response = [
      '@xmlns' => 'http://www.openarchives.org/OAI/2.0/',
      '@xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
      '@xsi:schemaLocation' => 'http://www.openarchives.org/OAI/2.0/ http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd',
      '@name' => 'OAI-PMH',
      'responseDate' => gmdate(self::OAI_DATE_FORMAT, \Drupal::time()->getRequestTime()),
      'request' => $base_oai_url,
    ];
    $verb = $this->currentRequest->get('verb');
    $identifier = $this->currentRequest->get('identifier');
    $set_id = $this->currentRequest->get('set');
    $verbs = [
      'Identify',
      'GetRecord',
      'ListIdentifiers',
      'ListMetadataFormats',
      'ListRecords',
      'ListSets'
    ];
    if (in_array($verb, $verbs)) {
      $this->response['request'] = [
        '@verb' => $verb,
        'oai-dc-string' => $base_oai_url
      ];
      switch ($verb) {
        case 'Identify':
          $this->identify();
          break;
      }
    }
    else {
      $this->response['error'] = [
        '@code' => 'badVerb',
        'oai-dc-string' =>  'Value of the verb argument is not a legal OAI-PMH verb, the verb argument is missing, or the verb argument is repeated.'
      ];
    }


    $response = new ResourceResponse($this->response, 200);
    $response->addCacheableDependency($this->response['request']);

    return $response;
  }

  protected function identify() {
    /**
    * @todo fetch earliest created date on entities as defined in config
    * i.e. eventually let admins choose entity_type and optionally bundles of entities to expose to OAI
    */
    $earliest_date = \Drupal::database()->query('SELECT MIN(`created`)
      FROM {node_field_data}')->fetchField();

    $this->response['Identify'] = [
      'repositoryName' => \Drupal::config('system.site')->get('name'),
      'baseURL' => $this->currentRequest->getSchemeAndHttpHost() . '/oai/request',
      'protocolVersion' => '2.0',
      'adminEmail' => \Drupal::config('system.site')->get('mail'),
      'earliestDatestamp' => gmdate(self::OAI_DATE_FORMAT, $earliest_date),
      'deletedRecord' => 'no',
      'granularity' => 'YYYY-MM-DDThh:mm:ssZ',
      'description' => [
        'oai-identifier' => [
          '@xmlns' => 'http://www.openarchives.org/OAI/2.0/oai-identifier',
          '@xsi:schemaLocation' => 'http://www.openarchives.org/OAI/2.0/oai-identifier http://www.openarchives.org/OAI/2.0/oai-identifier.xsd',
          'scheme' => 'oai',
          'repositoryIdentifier' => $this->currentRequest->getHttpHost(),
          'delimiter' => ':',
          'sampleIdentifier' => 'oai:' . $this->currentRequest->getHttpHost() . ':1'
        ]
      ]
    ];
  }
}
