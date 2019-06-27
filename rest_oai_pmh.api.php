<?php


/**
 * @file
 * Hooks for the REST OAI-PMH module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Provide additional metadata formats.
 *
 * By implementing hook_rest_oai_pmh_metadata_format_alter(), a module can provide additional
 * metadata formats to their OAI endpoint.
 *
 * To print XML metadata properly in OAI,
 * any additional metadata formats will also need to override
 * the metadata template file or preprocess hook to print the proper metadata for the format.
 * See the @link rest_oai_pmh_templates topic for more information.
 *
 * @param array $formats - a keyed array of metadata formats. Requires the following keys:
 *   - format: array containing keys
 *     - metadataPrefix: the name of the format that will be passed into OAI as metadataPrefix
 *     - schema: URL to the metadata schema
 *     - metadataNamespace: URL to the namespace
 *   - wrapper: an array with one key that's value is the name of XML element (e.g. oai:dc, mods, etc)
 *              the key/values of the single array will be the attributes for the element
 *
 */
function hook_rest_oai_pmh_metadata_format_alter(&$formats) {
  $formats['mods'] = [
    'format' => [
      'metadataPrefix' => 'mods',
      'schema' => 'http://www.loc.gov/standards/mods/v3/mods-3-3.xsd',
      'metadataNamespace' => 'http://www.loc.gov/mods/v3'
    ],
    /* used for ?verb=ListMetadataFormats. Above array will translate to
      <metadataFormat>
        <metadataPrefix>mods</metadataPrefix>
        <schema>http://www.loc.gov/standards/mods/v3/mods-3-3.xsd</schema>
        <metadataNamespace>http://www.loc.gov/mods/v3</metadataNamespace>
      </metadataFormat>
    */
    'wrapper' =>  [
      'mods' => [
        '@xmlns' => "http://www.loc.gov/mods/v3",
        '@xmlns:xsi' => "http://www.w3.org/2001/XMLSchema-instance",
        '@xsi:schemaLocation' => 'http://www.loc.gov/mods/v3 http://www.loc.gov/standards/mods/v3/mods-3-3.xsd',
      ]
      // used when printing records i.e. ?verb=ListRecords and ?verb=GetRecord
      // will translate to:
      // <mods xmlns="http://www.loc.gov/mods/v3" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.loc.gov/mods/v3 http://www.loc.gov/standards/mods/v3/mods-3-3.xsd">
      //</mods>
    ]
  ];
}


/**
 * @} End of "addtogroup hooks".
 */


/**
 * @defgroup rest_oai_pmh_templates REST OAI-PMH template files
 * @{
 * Describes how metadata is printed for records in OAI-PMH.
 *
 * A module that supplies a "mods" metadata format can customize what metadata is printed
 * when that format is requested in OAI by implementing the following template file:
 *
 * rest-oai-pmh-record--mods.html.twig
 *
 * @see template_preprocess_rest_oai_pmh_record()
 *
 * @}
 */