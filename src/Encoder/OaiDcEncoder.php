<?php

namespace Drupal\rest_oai_pmh\Encoder;

use Symfony\Component\Serializer\Encoder\XmlEncoder;

class OaiDcEncoder extends XmlEncoder {

  const ROOT_NODE_NAME = 'xml_root_node_name';
  /**
   * The formats that this Encoder supports.
   *
   * @var string
   */
  protected $format = 'oai_dc';

  /**
   * {@inheritdoc}
   */
  public function supportsEncoding($format) {
    return $format == $this->format;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDecoding($format) {
    return $format == $this->format;
  }

  /**
   * {@inheritdoc}
   */
  public function encode($data, $format, array $context = []) {
    $context[self::ROOT_NODE_NAME] = 'OAI-PMH';
    $xml = parent::encode($data, $format, $context);

    /**
     * @todo find better approach to represent XML nodes that are string values but contain @attributes with PHP arrays
     *
     * e.g.
     * $response = [
     *   'error' => [
     *      '@code' => 'badVerb',
     *      'content' => 'STRING VALUE'
     *   ]
     * ];
     * Needs to be encoded as <error code="badVerb">STRING VALUE</error>
     * But instead it's encoded as:
     * <error code="badVerb">
     *   <content>STRING VALUE</content>
     * </error>
     *
     * With Symfony's XML Encoder can not find any clear documentation whether this is possible or not.
     * So here we're just looking for a node we specially keyed for this case and removing those nodes.
     * Of course this is not ideal.
     */
    $search = [
      '<metadata-xml><![CDATA[',
      ']]></metadata-xml>',
      '<oai-dc-string>',
      '</oai-dc-string>',
    ];
    $replace = [
      '',
      '',
      '',
      '',
    ];

    return str_replace($search, $replace, $xml);
  }
}
