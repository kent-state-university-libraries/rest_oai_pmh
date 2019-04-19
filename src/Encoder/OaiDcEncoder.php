<?php

namespace Drupal\rest_oai_pmh\Encoder;

use Symfony\Component\Serializer\Encoder\EncoderInterface;
use Symfony\Component\Serializer\Encoder\DecoderInterface;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

class OaiDcEncoder extends XmlEncoder {

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

}