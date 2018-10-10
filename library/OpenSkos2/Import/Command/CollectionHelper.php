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

namespace OpenSkos2\Import\Command;

use OpenSkos2\Concept;
use OpenSkos2\Set;
use OpenSkos2\Person;
use OpenSkos2\ConceptScheme;
use OpenSkos2\Namespaces\DcTerms;
use OpenSkos2\Namespaces\OpenSkos;
use OpenSkos2\Namespaces\Skos;
use OpenSkos2\Rdf\Literal;
use OpenSkos2\Rdf\ResourceManager;
use OpenSkos2\ConceptManager;
use OpenSkos2\PersonManager;
use OpenSkos2\Institution;
use OpenSkos2\Import\Message;
use OpenSkos2\Rdf\ResourceCollection;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class CollectionHelper implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var ResourceManager
     */
    private $resourceManager;

    /**
     * @var ConceptManager
     */
    private $conceptManager;
    
    /**
     * @var PersonManager
     */
    private $personManager;

    /**
     * @var Institution
     */
    protected $tenant;
    
    /**
     * @var Set
     */
    protected $set;
    
    /**
     * @var Person
     */
    protected $person;
    
    
    /**
     * @var Message
     */
    protected $message;

    /**
     * @param ResourceManager $resourceManager
     * @param ConceptManager $conceptManager
     * @param Institution $tenant
     * @param Message $message
     */
    public function __construct(
        ResourceManager $resourceManager,
        ConceptManager $conceptManager,
        PersonManager $personManager,
        Institution $tenant,
        Message $message
    ) {
        $this->resourceManager = $resourceManager;
        $this->conceptManager = $conceptManager;
        $this->personManager = $personManager;
        $this->tenant = $tenant;
        $this->message = $message;
        $this->set = $this->resourceManager->fetchByUri($message->getSetUri(), Set::TYPE);
        $this->person = $message->getUser();
    }

    /**
     * Prepare the resource collection for importing.
     * @param ResourceCollection &$resourceCollection
     * @throws \Exception
     */
    public function prepare(ResourceCollection &$resourceCollection)
    {
        $removeResources = [];
        foreach ($resourceCollection as $key => &$resourceToInsert) {
            $alreadyExists = $this->resourceManager->askForUri($resourceToInsert->getUri());
            
            if ($alreadyExists && $this->message->getNoUpdates()) {
                $this->logger->warning("Skipping resource {$resourceToInsert->getUri()}, because it already exists");
                $removeResources[] = $key;
                continue;
            }

            if ($resourceToInsert instanceof Concept) {
                $this->prepareConcept($resourceToInsert, $alreadyExists);
                $this->logger->info("Has prepared concept {$resourceToInsert->getUri()}");
            } elseif ($resourceToInsert instanceof ConceptScheme) {
                $this->prepareConceptScheme($resourceToInsert, $alreadyExists);
                $this->logger->info("Has prepared concept scheme {$resourceToInsert->getUri()}");
            }
        }
        
        foreach ($removeResources as $removeKey) {
            $resourceCollection->offsetUnset($removeKey);
        }
    }
    
    /**
     * Makes concept specific changes prior to import.
     * @param Concept &$concept
     * @param bool $alreadyExists
     */
    protected function prepareConcept(Concept &$concept, $alreadyExists)
    {
        if ($concept->isBlankNode()) {
            $concept->selfGenerateUri($this->tenant, $this->set, $this->conceptManager);
        }

        if ($alreadyExists) {
            $currentVersion = $this->resourceManager->fetchByUri($concept->getUri(), Concept::TYPE);
        } else {
            $currentVersion = null;
        }

        // @TODO Is that $currentVersion/DATESUBMITTED logic needed at all. Remove and test.
        if ($alreadyExists && $currentVersion->hasProperty(DcTerms::DATESUBMITTED)) {
            $concept->setProperty(
                DcTerms::DATESUBMITTED,
                $currentVersion->getProperty(DcTerms::DATESUBMITTED)[0]
            );
        }

        if ($this->message->getIgnoreIncomingStatus()) {
            $concept->unsetProperty(OpenSkos::STATUS);
        }

        if ($this->message->getToBeChecked()) {
            $concept->addProperty(OpenSkos::TOBECHECKED, new Literal(true, null, Literal::TYPE_BOOL));
        }

        if ($this->message->getImportedConceptStatus() &&
            (!$concept->hasProperty(OpenSkos::STATUS))
        ) {
            $concept->addProperty(
                OpenSkos::STATUS,
                new Literal($this->message->getImportedConceptStatus())
            );
        }

        // @TODO Those properties has to have types, rather then ignoring them from a list
        $nonLangProperties = [Skos::NOTATION, OpenSkos::TENANT, OpenSkos::STATUS, OpenSkos::UUID];
        if ($this->message->getFallbackLanguage()) {
            foreach ($concept->getProperties() as $predicate => $properties) {
                foreach ($properties as $property) {
                    if (!in_array($predicate, $nonLangProperties)
                            && $property instanceof Literal
                            && $property->getType() === null
                            && $property->getLanguage() === null) {
                        $property->setLanguage($this->message->getFallbackLanguage());
                    }
                }
            }
        }
        
        

        $concept->ensureMetadata(
            $this->tenant,
            $this->set,
            $this->person,
            $this->personManager,
            $this->conceptManager->getLabelManager(),
            $currentVersion,
            true
        );
    }
    
    /**
     * Makes concept scheme specific changes prior to import.
     * @param ConceptScheme &$conceptScheme
     */
    protected function prepareConceptScheme(ConceptScheme &$conceptScheme, $alreadyExists)
    {
        if ($conceptScheme->isBlankNode()) {
            $conceptScheme->selfGenerateUri($this->tenant, $this->set, $this->conceptManager);
        }
        if ($alreadyExists) {
            $currentVersion = $this->resourceManager->fetchByUri($conceptScheme->getUri(), Concept::TYPE);
        } else {
            $currentVersion = null;
        }
      
      // @TODO Is that $currentVersion/DATESUBMITTED logic needed at all. Remove and test.
        if ($alreadyExists && $currentVersion->hasProperty(DcTerms::DATESUBMITTED)) {
            $conceptScheme->setProperty(
                DcTerms::DATESUBMITTED,
                $currentVersion->getProperty(DcTerms::DATESUBMITTED)[0]
            );
        }

        $conceptScheme->ensureMetadata(
            $this->tenant,
            $this->set,
            $this->person,
            $this->personManager,
            $this->conceptManager->getLabelManager(),
            $currentVersion,
            true
        );
    }
}
