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

namespace OpenSkos2\Rdf;

use EasyRdf\Http;
use EasyRdf\Sparql\Client;
use OpenSkos2\Bridge\EasyRdf;
use OpenSkos2\Exception\ResourceAlreadyExistsException;
use OpenSkos2\Exception\ResourceNotFoundException;
use OpenSkos2\Rdf\Serializer\NTriple;
use OpenSkos2\Namespaces\OpenSkos as OpenSkosNamespace;
use OpenSkos2\Namespaces\Rdf as RdfNamespace;
use OpenSkos2\Solr\ResourceManager as SolrResourceManager;
use Asparagus\QueryBuilder;

// @TODO A lot of things can be made without working with full documents, so that should not go through here
// For example getting a list of pref labels and uris

class ResourceManager
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * What is the basic resource for this manager.
     * Made to be extended and overwrited.
     * @var string NULL means any resource.
     */
    protected $resourceType = null;

    /**
     * @var \OpenSkos2\Solr\ResourceManager
     */
    protected $solrResourceManager;

    /**
     * Use that if inserting a large amount of resources.
     * Call commit at the end.
     * @return bool
     */
    public function getIsNoCommitMode()
    {
        return $this->solrResourceManager->getIsNoCommitMode();
    }

    /**
     * Use that if inserting a large amount of resources.
     * Call commit at the end.
     * @param bool
     */
    public function setIsNoCommitMode($isNoCommitMode)
    {
        $this->solrResourceManager->setIsNoCommitMode($isNoCommitMode);
    }

    /**
     * @param Client $client
     * @param SolrResourceManager $solrResourceManager
     */
    public function __construct(Client $client, SolrResourceManager $solrResourceManager)
    {
        $this->client = $client;
        $this->solrResourceManager = $solrResourceManager;
    }

    /**
     * Perform a full text query
     * lucene / solr queries are possible
     * for the available fields see schema.xml
     *
     * @param string $query
     * @param int $rows
     * @param int $start
     * @param int &$numFound output Total number of found records.
     * @param array $sorts
     * @return ConceptCollection
     */
    public function search($query, $rows = 20, $start = 0, &$numFound = 0, $sorts = null)
    {
        return $this->fetchByUris(
            $this->solrResourceManager->search($query, $rows, $start, $numFound, $sorts)
        );
    }

    /**
     * @param \OpenSkos2\Rdf\Resource $resource
     * @throws ResourceAlreadyExistsException
     */
    public function insert(Resource $resource)
    {
        // Put type if we have it and it is missing.
        if (!empty($this->resourceType) && $resource->isPropertyEmpty(RdfNamespace::TYPE)) {
            $resource->setProperty(RdfNamespace::TYPE, new Uri($this->resourceType));
        }

        $this->insertWithRetry(EasyRdf::resourceToGraph($resource));

        $this->solrResourceManager->insert($resource);
    }

    /**
     * Deletes and then inserts the resourse.
     * @param \OpenSkos2\Rdf\Resource $resource
     */
    public function replace(Resource $resource)
    {
        // @TODO Danger if insert fails. Need transaction or something.
        $this->delete($resource);
        $this->insert($resource);
    }

    /**
     * Soft delete resource , sets the openskos:status to deleted
     * and add a delete date.
     *
     * Be careful you need to add the full resource as it will be deleted and added again
     * do not only give a uri or part of the graph
     *
     * @param \OpenSkos2\Rdf\Resource $resource
     * @param Uri $user
     */
    public function deleteSoft(Resource $resource, Uri $user = null)
    {
        $resource->unsetProperty(OpenSkosNamespace::STATUS);
        $status = new Literal(Resource::STATUS_DELETED);
        $resource->addProperty(OpenSkosNamespace::STATUS, $status);

        $resource->unsetProperty(OpenSkosNamespace::DATE_DELETED);
        $resource->addProperty(OpenSkosNamespace::DATE_DELETED, new Literal(date('c'), null, Literal::TYPE_DATETIME));

        if ($user) {
            $resource->unsetProperty(OpenSkosNamespace::DELETEDBY, $user);
        }

        $this->replace($resource);
    }

    /**
     * @param Uri $resource
     */
    public function delete(Uri $resource)
    {
        $this->client->update("DELETE WHERE {<{$resource->getUri()}> ?predicate ?object}");

        $this->solrResourceManager->delete($resource);
    }

    /**
     * @todo Keep SOLR in sync
     * @param Object[] $simplePatterns
     */
    public function deleteBy($simplePatterns)
    {
        $query = "DELETE WHERE {\n ?subject ";
        foreach ($simplePatterns as $predicate => $value) {
            $query .= "<{$predicate}> " . $this->valueToTurtle($value) . ";\n";
        }
        $query .= "?predicate ?object\n}";

        $this->client->update($query);

        // @TODO remove from solr
    }

    /**
     * Delete all triples where pattern matches
     * @todo Keep SOLR in sync
     * @param Object|string $subject Put "?subject" to match all.
     * @param string $predicate
     * @param Object|string $object Put "?object" to match all.
     */
    public function deleteMatchingTriples($subject, $predicate, $object)
    {
        // @TODO Refactor. Not for resource manager.
        $query = 'DELETE WHERE {' . PHP_EOL;
        $query .= $subject == '?subject' ? '?subject' : $this->valueToTurtle($subject);
        $query .= ' <' . $predicate . '> ';
        $query .= $object == '?object' ? '?object' : $this->valueToTurtle($object);
        $query .= PHP_EOL . '}';

        $this->client->update($query);
    }

    /**
     * Fetch resource by uuid
     *
     * @param string $uuid
     * @return Resource
     * @throws ResourceNotFoundException
     */
    public function fetchByUuid($uuid)
    {
        $prefixes = [
            'openskos' => OpenSkosNamespace::NAME_SPACE,
        ];

        $lit = new \OpenSkos2\Rdf\Literal($uuid);
        $qb = new \Asparagus\QueryBuilder($prefixes);
        $query = $qb->describe('?subject')
            ->where('?subject', 'openskos:uuid', (new \OpenSkos2\Rdf\Serializer\NTriple)->serialize($lit));
        $data = $this->fetchQuery($query);

        if (count($data) == 0) {
            throw new ResourceNotFoundException(
                'The requested resource with openskos::uuid <' . $uuid . '> was not found.'
            );
        }

        if (count($data) > 1) {
            throw new \RuntimeException(
                'Something went very wrong. The requested resource with uuid <' . $uuid . '> was found more than once.'
            );
        }

        return $data[0];
    }

    /**
     * Fetches a single resource matching the uri.
     * @param string $uri
     * @return Resource
     * @throws ResourceNotFoundException
     */
    public function fetchByUri($uri)
    {
        $resource = new Uri($uri);
        try {
            $result = $this->query('DESCRIBE ' . (new NTriple)->serialize($resource));
            $resources = EasyRdf::graphToResourceCollection($result, $this->resourceType);
            // @TODO Add resourceType check.
        } catch (\Exception $exp) {
            throw new ResourceNotFoundException("Unable to fetch resource");
        }

        if (count($resources) == 0) {
            throw new ResourceNotFoundException(
                'The requested resource <' . $uri . '> was not found.'
            );
        }

        if (count($resources) > 1) {
            throw new \RuntimeException(
                'Something went very wrong. The requested resource <' . $uri . '> was found more than once.'
            );
        }

        return $resources[0];
    }

    /**
     * Fetches multiple records by list of uris.
     * @param string[] $uris
     * @return ResourceCollection
     * @throws ResourceNotFoundException
     */
    public function fetchByUris($uris)
    {
        /*
        DESCRIBE ?subject
        WHERE {
                ?subject ?predicate ?object .
                FILTER (
                    ?subject = <http://data.beeldengeluid.nl/gtaa/135633>
                    || ?subject = <http://data.beeldengeluid.nl/gtaa/350064>
                )
        }
        */

        $resources = EasyRdf::createResourceCollection($this->resourceType);

        if (!empty($uris)) {
            foreach (array_chunk($uris, 50) as $urisChunk) {
                $filters = [];
                foreach ($urisChunk as $uri) {
                    $filters[] = '?subject = ' . $this->valueToTurtle(new Uri($uri));
                }

                $query = new QueryBuilder();
                $query->describe('?subject')
                    ->where('?subject', '?predicate', '?object')
                    ->filter(implode(' || ', $filters));

                if (!empty($this->resourceType)) {
                    $query->where('?subject', '<' . RdfNamespace::TYPE . '>', '<' . $this->resourceType . '>');
                }

                foreach ($this->fetchQuery($query) as $resource) {
                    $resources->append($resource);
                }
            }

            // Keep the ordering of the passed uris.
            $resources->uasort(function (Resource $resource1, Resource $resource2) use ($uris) {
                $searchUris = array_values($uris);
                $ind1 = array_search($resource1->getUri(), $searchUris);
                $ind2 = array_search($resource2->getUri(), $searchUris);
                return $ind1 - $ind2;
            });
        }

        return $resources;
    }

    /**
     * Asks if a resource with the given uri exists.
     * @param string $uri
     * @param bool $checkAllResourceTypes
     * @return bool
     */
    public function askForUri($uri, $checkAllResourceTypes = false)
    {
        $query = '<' . $uri . '> ?predicate ?object';

        if (!$checkAllResourceTypes && !empty($this->resourceType)) {
            $query .= ' . ';
            $query .= '<' . $uri . '> <' . RdfNamespace::TYPE . '> <' . $this->resourceType . '>';
        }

        return $this->ask($query);
    }

    /**
     * Fetches full resources.
     * There is hardcoded order by uri.
     * @param Object[] $simplePatterns Example: [Skos::NOTATION => new Literal('AM002'),]
     * @param int $offset
     * @param int $limit
     * @param bool $ignoreDeleted Do not fetch resources which have openskos:status deleted.
     * @return ResourceCollection
     */
    public function fetch($simplePatterns = [], $offset = null, $limit = null, $ignoreDeleted = false)
    {
        /*
        DESCRIBE ?subject {
            SELECT DISTINCT ?subject
            WHERE {
                ?subject ?predicate ?object
            }
            ORDER BY ?subject
            LIMIT 10
            OFFSET 0
        }
        */

        if (!empty($this->resourceType)) {
            $newPatterns = [RdfNamespace::TYPE => new Uri($this->resourceType)];

            if ($this->resourceType === \OpenSkos2\Namespaces\Skos::CONCEPTSCHEME) {
                $simplePatterns = array_merge($newPatterns, $simplePatterns);
            } else {
                $simplePatterns = array_merge($simplePatterns, $newPatterns);
            }
        }

        $query = 'DESCRIBE ?subject {' . PHP_EOL;

        $query .= 'SELECT DISTINCT ?subject' . PHP_EOL;
        $where = $this->simplePatternsToQuery($simplePatterns, '?subject');

        if ($ignoreDeleted) {
            $where .= 'OPTIONAL { ?subject <' . OpenSkosNamespace::STATUS . '> ?status } . ';
            $where .= 'FILTER (!bound(?status) || ?status != \'' . Resource::STATUS_DELETED . '\')';
        }

        $query .= 'WHERE { ' . $where . '}';

        // We need some order
        // @TODO provide possibility to order on other predicates.
        // This will need to create ?subject ?predicate ?o1 .... ORDER BY ?o1
        $query .= PHP_EOL . 'ORDER BY ?subject';

        if ($limit !== null) {
            $query .= PHP_EOL . 'LIMIT ' . $limit;
        }

        if ($offset !== null) {
            $query .= PHP_EOL . 'OFFSET ' . $offset;
        }

        $query .= '}'; // end sub select

        $resources = $this->fetchQuery($query);

        // The order by part does not apply to the resources with describe.
        // So we need to order them again.
        // @TODO Find other solution - sort in jena, not here.
        // @TODO provide possibility to order on other predicates.
        $resources->uasort(
            function (Resource $resource1, Resource $resource2) {
                return strcmp($resource1->getUri(), $resource2->getUri());
            }
        );

        return $resources;
    }

    /**
     * Fetch list of namespaces which are used among the resources in the database.
     * @return ResourceCollection
     */
    public function fetchNamespaces()
    {
        // @TODO Not working, see \OpenSkos2\Namespaces::getRdfConceptNamespaces()
        return \OpenSkos2\Namespaces::getRdfConceptNamespaces();

        $query = 'DESCRIBE ?subject';
        $query .= PHP_EOL . ' LIMIT 0';

        // The EasyRdf\Sparql\Client does not gets the namespaces which fuseki provides.
        // Maybe it can be fixed/configured. Then this method can use the client directly.
        // @TODO DI
        $httpClient = Http::getDefaultHttpClient();
        $httpClient->resetParameters();

        $httpClient->setMethod('GET'); // @TODO Post for big queries
        $uri = $this->client->getQueryUri() . '?query=' . urlencode($query) . '&format=json';
        $httpClient->setUri($uri);

        $response = $httpClient->request();

        if (!$response->isSuccessful()) {
            throw new \RuntimeException(
                'HTTP request to ' . $uri . ' for getting namespaces failed: ' . $response->getBody()
            );
        }

        return json_decode($response->getBody(), true)['@context'];
    }

    /**
     * Counts distinct resources
     * @param Object[] $simplePatterns Example: [Skos::NOTATION => new Literal('AM002'),]
     * @return int
     */
    public function countResources($simplePatterns = [])
    {
        $query = 'SELECT (COUNT(DISTINCT ?subject) AS ?count)' . PHP_EOL;
        $query .= 'WHERE { ' . $this->simplePatternsToQuery($simplePatterns, '?subject') . ' }';

        /* @var $result \EasyRdf\Sparql\Result */
        $result = $this->query($query);

        return $result[0]->count->getValue();
    }

    /**
     * Asks for if the properties map has a match.
     * Example for $matchProperties:
     *
     * <code>
     * $matchProperties = [
     *     [
     *       "predicate" => Skos::NOTATION
     *       "value" => $concept->getProperty(Skos::NOTATION),
     *       "operator" => "=" // optional defaults to equals
     *     ],
     *     [
     *       "predicate" => Skos::INSCHEME
     *       "value" => $concept->getProperty(Skos::INSCHEME),
     *       "operator" => "!="
     *     ]
     * ];
     * </code>
     *
     * @param array $matchProperties
     * @param string $excludeUri
     * @param bool $ignoreDeleted
     * @return boolean
     */
    public function askForMatch(array $matchProperties, $excludeUri = null, $ignoreDeleted = true)
    {
        $select = '';
        $filter = 'FILTER(' . PHP_EOL;

        if (!empty($this->resourceType)) {
            $matchProperties[] = [
                'predicate' => RdfNamespace::TYPE,
                'value' => new Uri($this->resourceType),
            ];
        }

        $filters = [];
        foreach ($matchProperties as $i => $data) {
            $predicate = $data['predicate'];
            $operator = '=';

            if (isset($data['operator'])) {
                $operator = $data['operator'];
            }

            $select .= '?subject <' . $predicate . '> ?' . $i . '. ' . PHP_EOL;

            $value = $data['value'];
            if (!is_array($value)) {
                $value = [$value];
            }

            $newFilter = [];
            foreach ($value as $val) {
                $object = '?' . $i;
                if (isset($data['ignoreLanguage']) && $data['ignoreLanguage']) {
                    // Get only the simple string literal to compare without language.
                    $object = 'str(' . $object . ')';
                }

                $newFilter[] = $object . ' ' . $operator . ' ' . (new NTriple())->serialize($val);
            }

            $filters[] = '(' . implode(' || ', $newFilter) . ') ';
        }

        if ($ignoreDeleted) {
            $select .= '?subject <' . OpenSkosNamespace::STATUS . '> ?status. ' . PHP_EOL;
            $filters[] = '(!bound(?status) || ?status != \'' . Resource::STATUS_DELETED . '\')';
        }

        $filter .= implode(' && ', $filters) . ' ';

        if ($excludeUri) {
            $uri = new Uri($excludeUri);
            $filter .= '&& ?subject != ' . (new NTriple())->serialize($uri);
        }

        $ask = $select . $filter . ')';

        return $this->ask($ask);
    }

    /**
     * Fetch all resources matching the query.
     *
     * @param \Asparagus\QueryBuilder|string $query
     * @return ResourceCollection
     */
    public function fetchQuery($query)
    {
        if ($query instanceof \Asparagus\QueryBuilder) {
            $query = $query->getSPARQL();
        }

        $result = $this->query($query);
        return EasyRdf::graphToResourceCollection($result, $this->resourceType);
    }

    /**
     * Sends an ask query for if a match is found for the patterns and returns the boolean result.
     * @param string $query String representation of the patterns.
     * @return boolean
     */
    public function ask($query)
    {
        $query = 'ASK {' . PHP_EOL . $query . PHP_EOL . '}';
        return $this->query($query)->getBoolean();
    }

    /**
     * @return SolrResourceManager
     */
    public function getSolrManager()
    {
        return $this->solrResourceManager;
    }

    /**
     * Execute raw query
     * Retries on timeout, because when jena stays idle for some time, sometimes throws a timeout error.
     *
     * @param string $query
     * @return \EasyRdf\Graph
     * @throws \EasyRdf\Exception
     */
    public function query($query)
    {
        $maxTries = 3;
        $tries = 0;
        $ex = null;
        do {
            try {
                return $this->client->query($query);
            } catch (\EasyRdf\Exception $ex) {
                if (strpos($ex->getMessage(), 'timed out') === false) {
                    throw $ex;
                }
            }
            sleep(30);
            $tries ++;
        } while ($tries < $maxTries && $ex !== null);

        if ($ex !== null) {
            throw $ex;
        }
    }

    /**
     * Performs client->insert. Retry on timeout.
     * @param Graph $data
     * @return Http\Response
     * @throws \EasyRdf\Exception
     */
    protected function insertWithRetry($data)
    {
        $maxTries = 3;
        $tries = 0;
        $ex = null;
        do {
            try {
                return $this->client->insert($data);
            } catch (\EasyRdf\Exception $ex) {
                if (strpos($ex->getMessage(), 'timed out') === false) {
                    throw $ex;
                }
            }
            sleep(1);
            $tries ++;
        } while ($tries < $maxTries && $ex !== null);

        if ($ex !== null) {
            throw $ex;
        }
    }

    /**
     * @param Object $object
     * @return string
     * @throws \EasyRdf\Exception
     */
    protected function valueToTurtle(Object $object)
    {
        $serializer = new NTriple();
        return $serializer->serialize($object);
    }

    /**
     * Makes query (with full sparql patterns) from our search patterns.
     * @param Object[] $simplePatterns Example: [Skos::NOTATION => new Literal('AM002'),]
     * or [0 => ['?subject', Skos::NOTATION, new Literal('AM002'),]
     * @param string $subject
     * @return string
     */
    protected function simplePatternsToQuery($simplePatterns, $subject)
    {
        $query = '';
        if (!empty($simplePatterns)) {
            foreach ($simplePatterns as $predicate => $value) {
                if (!is_integer($predicate)) {
                    $query .= $subject . ' <' . $predicate . '> ' . $this->valueToTurtle($value) . ' .' . PHP_EOL;
                } else {
                    // Build a pattern like
                    // $value[0] <$value[1]> $value[2]
                    $query .= $value[0] instanceof Object ? $this->valueToTurtle($value[0]) : $value[0];
                    $query .= ' <' . $value[1] . '> ';
                    $query .= $value[2] instanceof Object ? $this->valueToTurtle($value[2]) : $value[2];
                    $query .= ' .';
                }
                $query .= PHP_EOL;
            }
        } else {
            // All subjects
            $query .= $subject . ' ?predicate ?object' . PHP_EOL;
        }

        return $query;
    }
}
