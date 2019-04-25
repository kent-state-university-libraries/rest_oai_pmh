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
use Drupal\Core\Render\RenderContext;
use Drupal\node\Entity\Node;

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

  private $response = [];

  private $error = FALSE;

  /**
   * A temporary variable to store the Node entity object that'll be exposed to OAI
   *
   * @var \Drupal\node\Entity\Node
   */
  private $entity;

  private $bundle;

  /**
   * Possible URL parameters passed in GET request
   */
  private $verb, $set_id, $identifier, $metadata_prefix, $resumption_token, $from, $until;

  private $set_field, $set_field_conditional, $set_nids = [-1];

  private $repository_name, $repository_email, $repository_path;

  private $expiration, $max_records;

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

    // read the config settings for this endpoint
    $config = \Drupal::config('rest_oai_pmh.settings');
    $fields = [
      'bundle',
      'set_field',
      'set_field_conditional',
      'repository_name',
      'repository_email',
      'repository_path',
      'expiration',
      'max_records',
    ];
    foreach ($fields as $field) {
      $this->{$field} = $config->get($field);
    }

    if (!$this->repository_path) {
      $this->repository_path = self::OAI_DEFAULT_PATH;
    }

    // use Drupal's key/value store for resumption tokens
    $this->keyValueStore = \Drupal::keyValue('rest_oai_pmh.resumption_token');
    // read what the next resumption token will be (akin to an auto-incremented integer)
    $this->next_token_id = $this->keyValueStore
          ->get('next_token_id');
    // if no resumption token has ever been set in this system, set the value to 1
    if (!$this->next_token_id) {
      $this->next_token_id = 1;
    }

    $this->request_time = \Drupal::time()->getRequestTime();

    $this->host = $this->currentRequest->getHttpHost();
    $this->base_oai_url = $this->currentRequest->getSchemeAndHttpHost() . $this->repository_path;

    // init the response array
    $this->response = [
      '@xmlns' => 'http://www.openarchives.org/OAI/2.0/',
      '@xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
      '@xsi:schemaLocation' => 'http://www.openarchives.org/OAI/2.0/ http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd',
      '@name' => 'OAI-PMH',
      'responseDate' => gmdate(self::OAI_DATE_FORMAT, $this->request_time),
      'request' => [
         'oai-dc-string' => $this->base_oai_url
       ],
    ];
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
    // make sure the user accessing has access to view nodes, since we're exposing them here
    if (!$this->currentUser->hasPermission('access content')) {
      throw new AccessDeniedHttpException();
    }

    // find all the possible URL parameters
    // @todo check for required/option here and throw errors instead of in each verb method
    $this->verb = $this->currentRequest->get('verb');
    $this->set_id = $this->currentRequest->get('set');
    $this->identifier = $this->currentRequest->get('identifier');
    $this->metadata_prefix = $this->currentRequest->get('metadataPrefix');
    $this->resumption_token = $this->currentRequest->get('resumptionToken');
    $this->from = $this->currentRequest->get('from');
    $this->until = $this->currentRequest->get('until');

    $verbs = [
      'GetRecord',
      'Identify',
      'ListIdentifiers',
      'ListMetadataFormats',
      'ListRecords',
      'ListSets'
    ];
    // if the request is for a valid verb, proceed
    if (in_array($this->verb, $verbs)) {
      $this->response['request']['@verb'] = $this->verb;
      // call the verb's respective method to populate the response with the required values
      $this->{$this->verb}();
    }
    // otherwise, we don't know what verb they're requesting, throw an error
    // @todo double check with OAI specs we should still be sending a 200 response in all cases
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
    if (empty($this->identifier)) {
      $this->setError('badArgument', 'Missing required argument identifier.');
    }

    // get the nid from the identifier string
    $components = explode(':', $this->identifier);
    $nid = empty($components[2]) ? 0 : $components[2];
    // try to load the node from the identifier
    $this->entity = Node::load($nid);

    // make sure we have a valid identifier string
    // and that the node exists
    // and that it's in the bundle the admin selected (if they selected one)
    if (count($components) != 3 ||
      $components[0] !== 'oai' ||
      $components[1] !== $this->host ||
      empty($this->entity) ||
      (!empty($this->bundle) && $this->entity->bundle() !== $this->bundle)) {
      $this->setError('idDoesNotExist', 'The value of the identifier argument is unknown or illegal in this repository.');
    }
    // make sure we have a metadata format
    if (empty($this->metadata_prefix)) {
      $this->setError('badArgument', 'Missing required argument metadataPrefix.');
    }
    // make sure it's a valid metadata format
    elseif (!in_array($this->metadata_prefix, ['oai_dc'])) {
      $this->setError('cannotDisseminateFormat', 'The metadata format identified by the value given for the metadataPrefix argument is not supported by the item or by the repository.');
    }

    if ($this->error) {
      unset($this->response['request']['@verb']);
    }
    else {
      $this->set_nids = empty($this->set_field) ? [] : $this->getSetNids();
      $this->response[$this->verb]['record'] = $this->getRecordById();
    }
  }

  protected function Identify() {
    /**
    * @todo fetch earliest created date on entities as defined in config
    * i.e. eventually let admins choose entity_type and optionally bundles of entities to expose to OAI
    */
    $earliest_date = \Drupal::database()->query('SELECT MIN(`created`)
      FROM {node_field_data}')->fetchField();

    $this->response[$this->verb] = [
      'repositoryName' => $this->repository_name,
      'baseURL' => $this->base_oai_url,
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
          'repositoryIdentifier' => $this->host,
          'delimiter' => ':',
          'sampleIdentifier' => 'oai:' . $this->host . ':1'
        ]
      ]
    ];
  }

  protected function ListMetadataFormats() {
    // @todo support more metadata formats
    $this->response[$this->verb] = [
      'metadataFormat' => [
        'metadataPrefix' => 'oai_dc',
        'schema' => 'http://www.openarchives.org/OAI/2.0/oai_dc.xsd',
        'metadataNamespace' => 'http://www.openarchives.org/OAI/2.0/oai_dc/'
      ],
    ];
  }


  protected function ListIdentifiers() {
    $nids = $this->getRecordNids();
    foreach ($nids as $nid) {
      $this->entity = Node::load($nid);
      $this->identifier = 'oai:' . $this->host . ':' . $nid;
      $this->response[$this->verb]['header'][] = $this->getRecordHeader();
    }
  }

  protected function ListRecords() {
    $nids = $this->getRecordNids();
    foreach ($nids as $nid) {
      $this->entity = Node::load($nid);
      $this->identifier = 'oai:' . $this->host . ':' . $nid;
      $this->response[$this->verb]['record'][] = $this->getRecordById();
    }
  }

  protected function ListSets() {
    if (empty($this->set_field)) {
      $this->setError('noSetHierarchy', 'The repository does not support sets.');
      return;
    }

    $this->response[$this->verb] = [];

    $nids = $this->getSetNids();
    foreach ($nids as $nid) {
      $this->entity = Node::load($nid);
      if ($this->entity && $this->entity->isPublished()) {
        // @todo check for set hierarchy and show accordingly
        // https://www.openarchives.org/OAI/2.0/guidelines-repository.htm#Sets-Hierarchy
        $this->response[$this->verb][] = [
          'set' => [
            'setSpec' => $this->entity->id(),
            'setName' => $this->entity->label(),
            'setDescription' => $this->getRecordMetadata()
          ]
        ];
      }
    }
  }

  private function setError($code, $string) {
    $this->response['error'][] = [
      '@code' => $code,
      'oai-dc-string' =>  $string,
    ];

    $this->error = TRUE;
  }

  private function getRecordById() {
    $record = [];
    $record['header'] = $this->getRecordHeader();
    $record['metadata'] = $this->getRecordMetadata();

    return $record;
  }

  /**
   * Helper function. Populate the header value for a record in the response array
   *
   * @see http://www.openarchives.org/OAI/openarchivesprotocol.html#Record
   */
  private function getRecordHeader() {
    $header = [
      'identifier' => $this->identifier,
    ];

    // set when the record was last updated
    $header['datestamp'] = gmdate(self::OAI_DATE_FORMAT, $this->entity->changed->value);

    // if sets are supported
    // AND the set is exposed to OAI
    // add the setSpec to the header
    if (!empty($this->set_field) &&
      $this->entity->hasField($this->set_field)) {
      foreach ($this->entity->get($this->set_field) as $set) {
        $set_nid = empty($set->entity) ? -1 : $set->entity->id();
        if (in_array($set_nid, $this->set_nids)) {
          $header['setSpec'][] = $set_nid;
        }
      }
    }

    return $header;
  }

  private function getRecordMetadata() {
    $metadata = [
      'oai_dc:dc' => [
        '@xmlns:oai_dc' => 'http://www.openarchives.org/OAI/2.0/oai_dc/',
        '@xmlns:dc' => 'http://purl.org/dc/elements/1.1/',
        '@xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
        '@xsi:schemaLocation' => 'http://www.openarchives.org/OAI/2.0/oai_dc/ http://www.openarchives.org/OAI/2.0/oai_dc.xsd',
      ]
    ];

    // @see https://www.lullabot.com/articles/early-rendering-a-lesson-in-debugging-drupal-8
    // can't just call metatag_generate_entity_metatags() here since it renders node token values,
    // which in turn screwing up caching on the REST resource
    // @todo ensure caching is working properly here
    $context = new RenderContext();
    $metatags = \Drupal::service('renderer')->executeInRenderContext($context, function() {
      return metatag_generate_entity_metatags($this->entity);
    });

    // go through all the metatags ['#type' => 'tag'] render elements
    // and find mappings for dublin core tags
    foreach ($metatags as $term => $metatag) {
      if (strpos($term, 'dcterms') !== FALSE) {
        // metatag_dc stores terms ad dcterms.ELEMENT
        // rename for oai_dc
        $term = str_replace('dcterms.', 'dc:', $metatag['#attributes']['name']);
        $metadata['oai_dc:dc'][$term][] = $metatag['#attributes']['content'];
      }
    }

    return $metadata;
  }

  private function getSetNids() {
    // can not use entityQuery here because it does not allow for conditionals on referenced entities
    // so just query the field SQL table directly
    $table = 'node__' . $this->set_field;
    $query = \Drupal::database()->select($table, 'f');

    // return the {node}.nid of the sets referenced
    $column = $this->set_field . '_target_id';
    $query->addField('f', $column);

    // if the repo admin specified a field to filter the sets exposed to OAI
    // add that filter to the query
    if ($this->set_field_conditional) {
      if (in_array($this->set_field_conditional, ['promote', 'sticky'])) {
        $set_filter_table = 'node_field_data';
        $set_filter_column = $this->set_field_conditional;
        $set_filter_id = 'nid';
      }
      else {
        $set_filter_table = 'node__' . $this->set_field_conditional;
        $set_filter_column = $this->set_field_conditional . '_value';
        $set_filter_id = 'entity_id';
      }
      $query->innerJoin($set_filter_table, 'set_filter', "set_filter.{$set_filter_id} = f.{$column}");
      $query->condition("set_filter.{$set_filter_column}", 1);
    }


    $nids = $query->groupBy($column)
      ->execute()
      ->fetchCol();

    return $nids;
  }

  private function getRecordNids() {
    $start = 0;
    $end = $this->max_records;
    // if a resumption token was passed in the URL, try to find it in the key store
    if ($this->resumption_token) {
      $token = $this->keyValueStore->get($this->resumption_token);
      // if we found a token and it's not expired, get the values needed
      if ($token &&
        $token['expires'] > $this->request_time &&
        $token['verb'] === $this->verb) {
        $metadata_prefix = $token['metadata_prefix'];
        $start = $token['cursor'];
        $set = $token['set'];
        $from = $token['from'];
        $until = $token['until'];
      }
      else {
        // if we found a token, and we're here, it means the token is expired
        // delete it from key value store
        if ($token && $token['expires'] < $this->request_time) {
          $this->keyValueStore->delete($resumption_token);
        }
        $this->setError('badResumptionToken', 'The value of the resumptionToken argument is invalid or expired.');
      }
    }
    // if a set parameter was passed, but this OAI endpoint doesn't support sets, throw error
    elseif (empty($this->set_field) && $this->set) {
      $this->setError('noSetHierarchy', 'The repository does not support sets.');
    }
    elseif (empty($this->metadata_prefix)) {
      $this->setError('badArgument', 'Missing required argument metadataPrefix.');
    }
    elseif (!in_array($this->metadata_prefix, ['oai_dc'])) {
      $this->setError('cannotDisseminateFormat', 'The metadata format identified by the value given for the metadataPrefix argument is not supported by the item or by the repository.');
    }
    if ($this->error) {
      return;
    }

    $query = \Drupal::entityQuery('node')
      ->condition('status', NODE_PUBLISHED);

    // if the OAI has a bundle filter, filter on that bundle
    if ($this->bundle) {
      $query->condition('type', $this->bundle);
    }

    // if sets are supported
    if (!empty($this->set_field)) {
      // do not include sets in the list of records
      $this->set_nids = $this->getSetNids();
      $query->condition('nid', $this->set_nids, 'NOT IN');

      // if set ID was passed in URL, filter on that
      // otherwise filter on all sets as defined on set field
      $set_nids = $this->set_id ? [$this->set_id] : $this->set_nids;
      $query->condition("{$this->set_field}.target_id", $set_nids, 'IN');
    }

    if ($this->from) {
      $this->response['request']['@from'] = $this->from;
      $query->condition('changed', strtotime($this->from), '>=');
    }
    if ($until) {
      $this->response['request']['@until'] = $this->until;
      $query->condition('changed', strtotime($this->until), '<=');
    }

    // get the total number of results to show in resumption token
    $count_query = clone $query;
    $total_count = $count_query->count()->execute();

    $this->response[$this->verb]['resumptionToken'] = [];

    // if the total results are more than what was returned here, add a resumption token
    if ($total_count > ($start + $end)) {
      // set the expiration date per the admin settings
      $expires = $this->request_time + $this->expiration;

      $this->response[$this->verb]['resumptionToken'] += [
        '@completeListSize' => $total_count,
        '@cursor' => $start,
        'oai-dc-string' => $this->next_token_id,
        '@expirationDate' => gmdate(self::OAI_DATE_FORMAT, $expires),
      ];

      // save the settings for the resumption token that will be shown in these results
      $token = [
        'metadata_prefix' => $metadata_prefix,
        'set' => $set,
        'cursor' => $start + $end,
        'expires' => $expires,
        'verb' => $this->response['request']['@verb'],
        'from' =>$from,
        'until' => $until
      ];
      $this->keyValueStore->set($this->next_token_id, $token);

      // increment the token id for the next resumption token that will show
      // @todo should we incorporate semaphores here to avoid possible duplicates?
      $this->next_token_id += 1;
      $this->keyValueStore->set('next_token_id', $this->next_token_id);
    }

    // finally, print all the records returned via our record query
    $query->range($start, $end);
    $nids = $query->execute();

    return $nids;
  }
}
