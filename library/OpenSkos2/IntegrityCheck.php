<?php

namespace OpenSkos2;

class IntegrityCheck
{

    private $customInit;

    public function __construct($manager)
    {
        $this->customInit = $manager->getCustomInitArray();
    }

    public function canBeDeleted($uri)
    {
        $integrity_check_on = $this->customInit["delete_integrity_check"];
        if ($integrity_check_on === "true") {
            if ($this->resourceManager->getResourceType() !== \OpenSkos2\Concept::TYPE) {
                $query = 'SELECT (COUNT(?s) AS ?COUNT) WHERE {?s ?p <' . $uri . '> . } LIMIT 1';
                $references = $this->resourceManager->query($query);
                if (($references[0]->COUNT->getValue()) > 0) {
                    throw new \Exception('The resource cannot be deleted because there are other resources '
                    . 'referring to it within this storage.', 400);
                }
            }
        } else {
            return true;
        }
    }
}
