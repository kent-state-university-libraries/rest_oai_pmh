# REST OAI-PMH

This REST OAI_PMH module exposes the metatag module's metatag_dc submodule's dublin core mappings to an OAI-PMH endpoint. For a full description of the module visit: https://www.drupal.org/project/rest_oai_pmh

To submit bug reports and feature suggestions, or to track changes visit: https://www.drupal.org/project/issues/rest_oai_pmh


## Install

Install via `composer require drupal/rest_oai_pmh`

 Visit https://www.drupal.org/node/1897420 for further information.

## Enable

When you enable this module a REST endpoint will be exposed, and any user that accesses your site that has the "access content" permission will be able to view the content in the endpoint

## Configure

1. In the Drupal permissions field, allow anonymous users to access the REST OAI-PMH endpoint if you want anonymous users to be able to access the endpoint. You'll also want to be sure anonymous users can access published content.
2. Set the Dublin Core Metatag mappings for your nodes. This mapping is how the endpoint will print metadata for the records and sets.
3. Go to /admin/config/services/rest/oai-pmh and supply your system configuration.

## Future Development

* The only supported OAI-PMH metadata formats currently is oai_dc. Plan to cross-walk oai_dc mapping into METS/MODS eventually
* Plan to also possibly use Drupal's RDF mapping to provide metadata instead of requiring metatag module
* Currently only nodes can be exposed to the OAI-PMH endpoint, with entity reference fields to other nodes used for the "set" functionality. This could be expanded if there's a need.
* Currently the only access checks happening are to ensure the user has access to view published nodes, and that each node exposed to OAI-PMH is published.