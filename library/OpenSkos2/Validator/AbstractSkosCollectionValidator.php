<?php

namespace OpenSkos2\Validator;

use OpenSkos2\SkosCollection as SkosCollection;
use OpenSkos2\Rdf\Resource as RdfResource;

abstract class AbstractSkosCollectionValidator extends AbstractResourceValidator
{
    function __construct($referencecheckOn=true){
       $this -> resourceType = SkosCollection::TYPE;
       $this->referenceCheckOn=$referencecheckOn; 
    }
    
    public function validate(RdfResource $resource)
    {
        if ($resource instanceof SkosCollection) {
            return $this->validateSkosCollection($resource);
        }
        return false;
    }
    
    abstract protected function validateSkosCollection(SkosCollection $skosCollection);
}