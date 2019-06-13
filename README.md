# REST OAI-PMH

This REST OAI_PMH module exposes dublin core field mappings to an OAI-PMH endpoint using Views. For a full description of the module visit: https://www.drupal.org/project/rest_oai_pmh

To submit bug reports and feature suggestions, or to track changes visit: https://www.drupal.org/project/issues/rest_oai_pmh


## Install

Install via `composer require drupal/rest_oai_pmh`

 Visit https://www.drupal.org/node/1897420 for further information.

## Enable

When you enable this module a REST endpoint will be exposed, and any user that accesses your site that has the "Access GET on OAI-PMH" permission and can view the entities exposed to the endpoint will have access to your OAI-PMH endpoint.

## Configure

1. If you haven't already, set the Dublin Core Metatag mappings for the entities you want to expose to OAI-PMH. This can be done with the RDF module, Metatag's Dublin Core module, or a custom module/theme overriding this module's template. The mapping is how the endpoint will print metadata for the records and sets.
2. Create a an Entity Reference View display for each set you want exposed to OAI-PMH. Optionally, in your View you can provide a contextual filter to an Entity Reference field. If you do so, the entities in that field will be used as the sets.
3. In the Drupal permissions admin UI, grant anonymous users the "Access GET on OAI-PMH" permissions" permission if you want anonymous users (i.e. OAI-PMH Harvesters) to be able to access the endpoint. You'll also want to be sure anonymous users can view published content.
![Screenshot of permissions field](https://www.drupal.org/files/project-images/Screen%20Shot%20on%202019-04-24%20at%2011-32-43.png)
4. Go to the REST OAI-PMH configuration form at /admin/config/services/rest/oai-pmh and supply your system configuration.

## Future Development

* The only supported OAI-PMH metadata formats currently is oai_dc. Plan to cross-walk oai_dc mapping into METS/MODS eventually