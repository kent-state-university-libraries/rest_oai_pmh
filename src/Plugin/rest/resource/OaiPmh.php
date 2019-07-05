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
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\node\Entity\Node;
use Drupal\views\Views;

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

  const OAI_DEFAULT_PATH = '/oai/request';
  const OAI_DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

  /**
   * A current user instance.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  protected $currentRequest;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  private $response = [];

  private $error = FALSE;

  private $entity;

  private $bundle;

  private $verb;

  private $view_displays;

  private $repository_name, $repository_email, $repository_path;

  private $expiration;

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
    Request $currentRequest,
    ModuleHandlerInterface $module_handler) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);

    $this->currentUser = $current_user;
    $this->currentRequest = $currentRequest;
    $this->moduleHandler = $module_handler;


    // read the config settings for this endpoint
    // and set some properties for this class from the config
    $config = \Drupal::config('rest_oai_pmh.settings');
    $fields = [
      'bundle',
      'view_displays',
      'repository_name',
      'repository_email',
      'repository_path',
      'expiration',
      'support_sets',
      'mapping_source',
    ];
    foreach ($fields as $field) {
      $this->{$field} = $config->get($field);
    }

    // make sure the path is always set
    // if we don't have one, resort to default value
    if (!$this->repository_path) {
      $this->repository_path = self::OAI_DEFAULT_PATH;
    }

    // create a key/value store for resumption tokens
    $this->keyValueStore = \Drupal::keyValue('rest_oai_pmh.resumption_token');
    $this->next_token_id = $this->keyValueStore
          ->get('next_token_id');
    if (!$this->next_token_id) {
      $this->next_token_id = 1;
    }
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
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('module_handler')
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
    // init a basic response used by all verbs
    $base_oai_url = $this->currentRequest->getSchemeAndHttpHost() . $this->repository_path;
    $this->response = [
      '@xmlns' => 'http://www.openarchives.org/OAI/2.0/',
      '@xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
      '@xsi:schemaLocation' => 'http://www.openarchives.org/OAI/2.0/ http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd',
      '@name' => 'OAI-PMH',
      'responseDate' => gmdate(self::OAI_DATE_FORMAT, \Drupal::time()->getRequestTime()),
      'request' => [
         'oai-dc-string' => $base_oai_url
       ],
    ];

    $verb = $this->currentRequest->get('verb');
    $set_id = $this->currentRequest->get('set');
    $verbs = [
      'GetRecord',
      'Identify',
      'ListIdentifiers',
      'ListMetadataFormats',
      'ListRecords',
      'ListSets'
    ];
    // make sure a valid verb was passed in as a GET parameter
    // if so, call the respective function implemented in this class
    if (in_array($verb, $verbs)) {
      $this->response['request']['@verb'] = $this->verb = $verb;
      $this->{$verb}();
    }
    // if not a valid verb, print the error message
    else {
     $this->setError('badVerb', 'Value of the verb argument is not a legal OAI-PMH verb, the verb argument is missing, or the verb argument is repeated.');
    }

    $response = new ResourceResponse($this->response, 200);

    // @todo for now disabling cache altogether until can come up with sensible method if there is one
    $response->addCacheableDependency([
      '#cache' => [
        'max-age' => 0
      ]
    ]);

    return $response;
  }

  protected function GetRecord() {

    $identifier = $this->currentRequest->get('identifier');
    if (empty($identifier)) {
      $this->setError('badArgument', 'Missing required argument identifier.');
    }

    $this->loadEntity($identifier);

    // check to ensure the identifier is valid
    // and an entity was loaded
    $components = explode(':', $identifier);
    if (count($components) != 3 ||
      $components[0] !== 'oai' ||
      $components[1] !== $this->currentRequest->getHttpHost() ||
      empty($this->entity)) {
      $this->setError('idDoesNotExist', 'The value of the identifier argument is unknown or illegal in this repository.');
    }


    $this->metadataPrefix = $this->currentRequest->get('metadataPrefix');
    $this->checkMetadataPrefix();

    // check if an error was thrown
    if ($this->error) {
      // per OAI specs, remove the verb from the response
      unset($this->response['request']['@verb']);
    }
    // if no error, print the record
    else {
      $this->response['request']['@metadataPrefix'] = $this->metadataPrefix;
      $this->response[$this->verb]['record'] = $this->getRecordById($identifier);
    }
  }

  protected function Identify() {
    // query our table to see the oldest entity exposed to OAI
    $earliest_date = \Drupal::database()->query('SELECT MIN(created)
      FROM {rest_oai_pmh_record}')->fetchField();

    $this->response[$this->verb] = [
      'repositoryName' => $this->repository_name,
      'baseURL' => $this->currentRequest->getSchemeAndHttpHost() . $this->repository_path,
      'protocolVersion' => '2.0',
      'adminEmail' => $this->repository_email,
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
          'sampleIdentifier' => 'oai:' . $this->currentRequest->getHttpHost() . ':node-1'
        ]
      ]
    ];
  }

  protected function ListMetadataFormats() {
    // @todo support more metadata formats
    $this->response[$this->verb]['metadataFormat'] = $this->getMetadataFormats();
  }

  protected function ListIdentifiers() {
    $entities = $this->getRecordIds();
    foreach ($entities as $entity) {
      $this->oai_entity = $entity;
      $identifier = $this->buildIdentifier($entity);
      $this->loadEntity($identifier, TRUE);
      $this->response[$this->verb]['header'][] = $this->getHeaderById($identifier);
    }
  }

  protected function ListRecords() {
    $entities = $this->getRecordIds();
    foreach ($entities as $entity) {
      $this->oai_entity = $entity;
      $identifier = $this->buildIdentifier($entity);
      $this->loadEntity($identifier, TRUE);
      $this->response[$this->verb]['record'][] = $this->getRecordById($identifier);
    }
  }

  protected function ListSets() {
    // throw an error if no Views set for OAI, or sets are explicitly not supported
    if (count($this->view_displays) == 0 || empty($this->support_sets)) {
      $this->setError('noSetHierarchy', 'The repository does not support sets.');
      return;
    }

    $this->response[$this->verb] = [];

    $sets = \Drupal::database()->query('SELECT set_id, label FROM {rest_oai_pmh_set}');
    foreach ($sets as $set) {
      $this->response[$this->verb][] = [
        'set' => [
          'setSpec' => $set->set_id,
          'setName' => $set->label,
        ]
      ];
    }
  }

  /**
   * Helper function.
   *
   * A lot of different scenarios can cause an error based on GET parameters supplied
   * so have a standard convention to record these errors and print them in XML
   */
  protected function setError($code, $string) {
    $this->response['error'][] = [
      '@code' => $code,
      'oai-dc-string' =>  $string,
    ];
    $this->error = TRUE;
  }

  protected function getRecordById($identifier) {
    $record = [];
    $record['header'] = $this->getHeaderById($identifier);
    $record['metadata'] = $this->getRecordMetadata();

    return $record;
  }

  protected function getHeaderById($identifier) {
    $header = [
      'identifier' => $identifier,
    ];

    // if the entity being exposed to OAI has a changed field
    // print that in the header
    if ($this->entity->hasField('changed')) {
      $header['datestamp'] = gmdate(self::OAI_DATE_FORMAT, $this->entity->changed->value);
    }

    // if sets are supported
    // print the sets this record belongs to
    if (!empty($this->oai_entity) && !empty($this->support_sets)) {
      $sets = explode(',', $this->oai_entity->sets);
      foreach ($sets as $set) {
        $header['setSpec'][] = $set;
      }
    }
    return $header;
  }

  protected function getRecordMetadata() {
    $this->metadataPrefix = $this->currentRequest->get('metadataPrefix');
    $metadata = $this->getMetadataWrapper();

    // @see https://www.lullabot.com/articles/early-rendering-a-lesson-in-debugging-drupal-8
    // can't just call metatag_generate_entity_metatags() here since it renders node token values,
    // which in turn screwing up caching on the REST resource
    // @todo ensure caching is working properly here
    $context = new RenderContext();
    $xml = \Drupal::service('renderer')->executeInRenderContext($context, function() {
      $element = [
        '#theme' => 'rest_oai_pmh_record__' . $this->metadataPrefix,
        '#entity_type' => $this->entity->getEntityTypeId(),
        '#entity_id' => $this->entity->id(),
        '#entity' => $this->entity,
        '#mapping_source' => $this->mapping_source,
        '#metadata_prefix' =>  $this->metadataPrefix,
      ];

      return render($element);
    });

    $key = key($metadata);
    $metadata[$key]['metadata-xml'] = trim($xml);

    return $metadata;
  }

  private function getRecordIds() {
    $verb = $this->response['request']['@verb'];
    $resumption_token = $this->currentRequest->get('resumptionToken');
    $this->metadataPrefix = $this->currentRequest->get('metadataPrefix');
    $set = $this->currentRequest->get('set');
    $from = $this->currentRequest->get('from');
    $until = $this->currentRequest->get('until');
    $cursor = 0;
    $completeListSize = 0;
    $views_total = [];
    // if a resumption token was passed in the URL, try to find it in the key store
    if ($resumption_token) {
      $token = $this->keyValueStore->get($resumption_token);
      // if we found a token and it's not expired, get the values needed
      if ($token &&
        $token['expires'] > \Drupal::time()->getRequestTime() &&
        $token['verb'] == $this->verb) {
        $this->metadataPrefix = $token['metadata_prefix'];
        $cursor = $token['cursor'];
        $set = $token['set'];
        $from = $token['from'];
        $until = $token['until'];
        $completeListSize = $token['completeListSize'];
      }
      else {
        // if we found a token, and we're here, it means the token is expired
        // delete it from key value store
        if ($token && $token['expires'] < \Drupal::time()->getRequestTime()) {
          $this->keyValueStore->delete($resumption_token);
        }
        $this->setError('badResumptionToken', 'The value of the resumptionToken argument is invalid or expired.');
      }
    }
    // if a set parameter was passed, but this OAI endpoint doesn't support sets, throw error
    elseif ((empty($this->support_sets) || empty($this->view_displays)) && $set) {
      $this->setError('noSetHierarchy', 'The repository does not support sets.');
    }

    $this->checkMetadataPrefix();

    if ($this->error) {
      return;
    }
    else {
      // our {rest_oai_pmh_set} stores the pager information for the Views exposed to OAI
      // to play it safe, make the limit // max results returned be the smallest pager size for all the Views exposed to OAI
      $end = \Drupal::database()->query('SELECT MIN(`limit`) FROM {rest_oai_pmh_set}')->fetchField();
      $this->response['request']['@metadataPrefix'] = $this->metadataPrefix;
    }

    // query our {rest_oai_pmh_*} tables to get records that are exposed to OAI
    $query = \Drupal::database()->select('rest_oai_pmh_record', 'r');
    $query->innerJoin('rest_oai_pmh_member', 'm', 'm.entity_id = r.entity_id AND m.entity_type = r.entity_type');
    $query->innerJoin('rest_oai_pmh_set', 's', 's.set_id = m.set_id');
    $query->fields('r', ['entity_id', 'entity_type']);
    $query->addExpression('GROUP_CONCAT(m.set_id)', 'sets');
    $query->groupBy('r.entity_type, r.entity_id');

    // if set ID was passed in URL, filter on that
    // otherwise filter on all sets as defined on set field
    if ($set) {
      $this->set_ids = [$set];
      $query->condition('m.set_id', $set);
    }

    // if from was passed as  GET parameter, filter on that
    if ($from) {
      $this->response['request']['@from'] = $from;
      $query->condition('changed', strtotime($from), '>=');
    }
    // if until was passed as  GET parameter, filter on that
    if ($until) {
      $this->response['request']['@until'] = $until;
      $query->condition('changed', strtotime($until), '<=');
    }

    // if we haven't checked the complete list size yet (i.e. this isn't a call from a resumption token)
    // get the complete list size for this request
    if (empty($completeListSize)) {
      $completeListSize = $query->countQuery()->execute()->fetchField();
    }

    $this->response[$this->verb]['resumptionToken'] = [];

    // if the total results are more than what was returned here, add a resumption token
    if ($completeListSize > ($cursor + $end) && $end > 0) {
      // set the expiration date per the admin settings
      $expires = \Drupal::time()->getRequestTime() + $this->expiration;

      $this->response[$this->verb]['resumptionToken'] += [
        '@completeListSize' => $completeListSize,
        '@cursor' => $cursor,
        'oai-dc-string' => $this->next_token_id,
        '@expirationDate' => gmdate(self::OAI_DATE_FORMAT, $expires),
      ];

      // save the settings for the resumption token that will be shown in these results
      $token = [
        'metadata_prefix' => $this->metadataPrefix,
        'set' => $set,
        'cursor' => $cursor + $end,
        'expires' => $expires,
        'verb' => $this->response['request']['@verb'],
        'from' => $from,
        'until' => $until,
        'completeListSize' => $completeListSize,
      ];
      $this->keyValueStore->set($this->next_token_id, $token);

      // increment the token id for the next resumption token that will show
      // @todo should we incorporate semaphores here to avoid possible duplicates?
      $this->next_token_id += 1;
      $this->keyValueStore->set('next_token_id', $this->next_token_id);
    }

    // put a pager on the query if there's a pager on the Views exposed to OAI
    if ($end > 0) {
      $query->range($cursor, $end);
    }

    $entities = $query->execute();

    return $entities;
  }

  /**
   * Helper function. Create an OAI identifier for the given entity
   */
  protected function buildIdentifier($entity) {
    $identifier = 'oai:';
    $identifier .= $this->currentRequest->getHost();
    $identifier .= ':';
    $identifier .= $entity->entity_type;
    $identifier .= '-' . $entity->entity_id;

    return $identifier;
  }

  /**
   * Helper function. Load an entity to be printed in OAI endpoint
   *
   *
   * @param $identifier - string of OAI identifier for a record
   * @param $skip_check - boolean on whether to ensure entity being passed in $identifier is indeed exposed to OAI. Some OAI verbs, like ListRecords, are querying only the entities that are indeed exposed to OAI. Other verbs, like GetRecord, get an identifier passed and are asked for the metadata for that record. So need to check that the entity is indeed in OAI
   */
  protected function loadEntity($identifier, $skip_check = FALSE) {
    $entity = FALSE;
    $components = explode(':', $identifier);
    $id = empty($components[2]) ? FALSE : $components[2];
    if ($id) {
      list($entity_type, $entity_id) = explode('-', $id);

      try {
        // if we need to check whether the entity is in OAI, do so
        // we don't do this for ListRecords b/c we already know the entity is in OAI since we queried it from the table we're checking against
        // but for GetRecord, the user is passing the identifier, so we need to ensure it's legit
        // basically just a performance enhancement to not always check
        if (!$skip_check) {

          // fetch all sets the record belongs to
          // even if sets aren't supported by OAI, our system still stores the set information
          // so it's a reliable method to check
          // PLUS we get all the sets the record belongs to to print in <header>
          $d_args = [
            ':type' => $entity_type,
            ':id' => $entity_id
          ];
          $in_oai_view = \Drupal::database()->query('SELECT GROUP_CONCAT(set_id) FROM {rest_oai_pmh_record} r
            INNER JOIN {rest_oai_pmh_member} m ON m.entity_id = r.entity_id AND m.entity_type = r.entity_type
            WHERE r.entity_id = :id
              AND r.entity_type = :type
            GROUP BY r.entity_id', $d_args)->fetchField();

          // store the set membership from out table so we can print set membership in <header>
          $this->oai_entity = (object)['sets' => $in_oai_view];
        }

        // if we're skipping the OAI check OR we didn't skip the check, and the record is in OAI
        // load the entity
        if ($skip_check || $in_oai_view) {
          $storage = \Drupal::entityTypeManager()->getStorage($entity_type);
          $entity = $storage->load($entity_id);
        }
      }
      catch (Exception $e) {}
    }

    // make sure the entity was loaded properly
    // AND the person viewing has access
    $this->entity = $entity && $entity->access('view') ? $entity : FALSE;
  }

  protected function checkMetadataPrefix() {
    // if no metadata prefix passed into request, throw error
    if (empty($this->metadataPrefix)) {
      $this->setError('badArgument', 'Missing required argument metadataPrefix.');
    }
    // else go through all the supported metadata prefixes and see if the value passed is supported
    else {
      $supported = FALSE;
      foreach ($this->getMetadataFormats() as $format) {
        if ($format['metadataPrefix'] === $this->metadataPrefix) {
          $supported = TRUE;
          break;
        }
      }
      if (!$supported) {
        $this->setError('cannotDisseminateFormat', 'The metadata format identified by the value given for the metadataPrefix argument is not supported by the item or by the repository.');
      }
    }
  }

  protected function getMetadataFormats() {
    $formats = [];
    $formats[] = [
      'metadataPrefix' => 'oai_dc',
      'schema' => 'http://www.openarchives.org/OAI/2.0/oai_dc.xsd',
      'metadataNamespace' => 'http://www.openarchives.org/OAI/2.0/oai_dc/'
    ];

    $additional_formats = [];
    $this->moduleHandler->alter('rest_oai_pmh_metadata_format', $additional_formats);

    foreach ($additional_formats as $format) {
      $formats[] = $format['format'];
    }

    return array_values($formats);
  }

  /**
   * Helper function. Get the XML element that will wrap the metadata for each record
   */
  protected function getMetadataWrapper() {
    $metadata_wrapper = [];
    // if requesting oai_dc, fulfill the request
    if ($this->metadataPrefix === 'oai_dc') {
      $metadata_wrapper['oai_dc:dc'] = [
        '@xmlns:oai_dc' => 'http://www.openarchives.org/OAI/2.0/oai_dc/',
        '@xmlns:dc' => 'http://purl.org/dc/elements/1.1/',
        '@xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
        '@xsi:schemaLocation' => 'http://www.openarchives.org/OAI/2.0/oai_dc/ http://www.openarchives.org/OAI/2.0/oai_dc.xsd',
      ];
    }
    // else, get
    else {
      $formats = [];
      $this->moduleHandler->alter('rest_oai_pmh_metadata_format', $formats);
      foreach ($formats as $key => $format) {
        if ($format['format']['metadataPrefix'] !== $this->metadataPrefix) {
          unset($formats[$key]);
        }
      }
      if (count($formats) != 1) {
        $this->setError('cannotDisseminateFormat', 'The metadata format identified by the value given for the metadataPrefix argument is not supported by the item or by the repository.');
        return;
      }
      else {
        $metadata_wrapper = current($formats)['wrapper'];
      }
    }

    return $metadata_wrapper;
  }
}
