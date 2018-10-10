<?php

/**
 * OpenSKOS
 *
 * LICENSE
 *
 * This source file is subject to the GPLv3 license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.txt
 *
 * @category   OpenSKOS
 * @package    OpenSKOS
 * @copyright  Copyright (c) 2015 Picturae (http://www.picturae.com)
 * @author     Picturae
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GPLv3
 */

namespace OpenSkos2\Validator\Concept;

use OpenSkos2\Concept;
use OpenSkos2\Namespaces\Skos;
use OpenSkos2\Namespaces\OpenSkos;
use OpenSkos2\Validator\AbstractConceptValidator;

class UniquePreflabelInInstitution extends AbstractConceptValidator
{

    /**
     * Ensure the preflabel does not already exists in the scheme
     *
     * @param Concept $concept
     * @return bool
     */
    protected function validateConcept(Concept $concept)
    {
        $schemes = $concept->getProperty(Skos::INSCHEME);
        $preflabel = $concept->getProperty(Skos::PREFLABEL);
        foreach ($preflabel as $label) {
            foreach ($schemes as $scheme) {
                if ($this->labelExistsInTenant($concept, $label, $scheme)) {
                    $this->errorMessages[] = "The pref label $label already exists in the tenant";
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Check if the preflabel already exists in scheme
     *
     * @param Concept $concept
     * @param \OpenSkos2\Rdf\Literal $label
     * @param \OpenSkos2\Rdf\Uri $scheme
     * @return boolean
     */
    private function labelExistsInTenant(Concept $concept, \OpenSkos2\Rdf\Literal $label, \OpenSkos2\Rdf\Uri $scheme)
    {
        $uri = null;
        if (!$concept->isBlankNode()) {
            $uri = $concept->getUri();
        }

        $ntriple = new \OpenSkos2\Rdf\Serializer\NTriple();
        $escapedLabel = $ntriple->serialize($label);
        $escapedScheme = $ntriple->serialize($scheme);

        $query = '
              #Find all tenants linked to concepts which have this preflabel
              ?subject <' . Skos::PREFLABEL . '> ' . $escapedLabel . ' .
              ?subject <' . Skos::INSCHEME . '> ?set. 
              ?set <'.OpenSkos::TENANT.'> ?setInstitution.
              ?subject <' . OpenSkos::STATUS . '> ?status
  
              #Find the tenant for the conceptscheme we\'re analysing
              {
                SELECT ?conceptTenant 
                WHERE {
                  '.$escapedScheme. ' <'. OpenSkos::TENANT .'> $conceptTenant 
                }
              }
  
              FILTER(
                  ?subject != ' . $ntriple->serialize($concept) . '  #Don\'t check ourselves
                  && 
                  ?setInstitution = ?conceptTenant
                  &&
                  ?status != \''.Concept::STATUS_DELETED.'\' 
                  && 
                  ?status != \''.Concept::STATUS_OBSOLETE.'\'
              )
              ';

        return $this->resourceManager->ask($query);
    }
}
