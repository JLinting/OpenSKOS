<?php

/*
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

namespace OpenSkos2\Api;

use OpenSkos2\Api\Exception\ApiException;
use OpenSkos2\Api\Exception\NotFoundException;
use OpenSkos2\Converter\Text;
use OpenSkos2\Namespaces\Dc;
use OpenSkos2\Namespaces\DcTerms;
use OpenSkos2\Namespaces\OpenSkos;
use OpenSkos2\Namespaces\Rdf;
use OpenSkos2\Rdf\Literal;
use OpenSkos2\Validator\Concept\UniquePreflabelInScheme;
use OpenSKOS_Db_Table_Row_Collection;
use OpenSkos2\Api\Exception\InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Diactoros\Response;
use Zend\Diactoros\Stream;

/**
 * Map an API request from the old application to still work with the new backend on Jena
 */
class Concept
{

    const QUERY_DESCRIBE = 'describe';
    const QUERY_COUNT = 'count';

    /**
     * Concept manager
     *
     * @var \OpenSkos2\ConceptManager
     */
    private $manager;

    /**
     * Amount of concepts to return
     *
     * @var int
     */
    private $limit = 20;

    /**
     *
     * @param ServerRequestInterface $request
     */
    public function __construct(\OpenSkos2\ConceptManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Map the following requests
     *
     * /api/find-concepts?q=Kim%20Holland
     * /api/find-concepts?&fl=prefLabel,scopeNote&format=json&q=inScheme:"http://uri"
     * /api/find-concepts?format=json&fl=uuid,uri,prefLabel,class,dc_title&id=http://data.beeldengeluid.nl/gtaa/27140
     * /api/concept/82c2614c-3859-ed11-4e55-e993c06fd9fe.rdf
     *
     * @param ServerRequestInterface $request
     * @param string $context
     * @return ResponseInterface
     */
    public function findConcepts(ServerRequestInterface $request, $context)
    {

        $solr2sparql = new Query\Solr2Sparql($request);

        $params = $request->getQueryParams();

        // offset
        $start = 0;
        if (!empty($params['start'])) {
            $start = (int)$params['start'];
        }

        // limit
        $limit = $this->limit;
        if (isset($params['rows']) && $params['rows'] < 1001) {
            $limit = (int)$params['rows'];
        }

        $query = $solr2sparql->getSelect($limit, $start);
        $count = $solr2sparql->getCount();

        $concepts = $this->manager->fetchQuery($query);

        $countResult = $this->manager->query($count);
        $total = $countResult[0]->count->getValue();

        $result = new ConceptResultSet($concepts, $total, $start);

        switch ($context) {
            case 'json':
                $response = (new \OpenSkos2\Api\Response\ResultSet\JsonResponse($result))->getResponse();
                break;
            case 'rdf':
                $response = (new \OpenSkos2\Api\Response\ResultSet\RdfResponse($result))->getResponse();
                break;
            default:
                throw new InvalidArgumentException('Invalid context: ' . $context);
        }

        return $response;
    }

    /**
     * Get PSR-7 response for concept
     *
     * @param $request \Psr\Http\Message\ServerRequestInterface
     * @param string $context
     * @throws NotFoundException
     * @throws InvalidArgumentException
     * @return ResponseInterface
     */
    public function getConceptResponse(ServerRequestInterface $request, $uuid, $context)
    {
        $concept = $this->getConcept($uuid);

        switch ($context) {
            case 'json':
                $response = (new \OpenSkos2\Api\Response\Detail\JsonResponse($concept))->getResponse();
                break;
            case 'jsonp':
                $params = $request->getQueryParams();
                $response = (new \OpenSkos2\Api\Response\Detail\JsonpResponse(
                    $concept,
                    $params['callback']
                ))->getResponse();
                break;
            case 'rdf':
                $response = (new \OpenSkos2\Api\Response\Detail\RdfResponse($concept))->getResponse();
                break;
            default:
                throw new InvalidArgumentException('Invalid context: ' . $context);
        }

        return $response;
    }

    /**
     * Get openskos concept
     *
     * @param string $uuid
     * @throws NotFoundException
     * @throws Exception\DeletedException
     * @return \OpenSkos2\Concept
     */
    public function getConcept($uuid)
    {
        /* @var $concept \OpenSkos2\Concept */
        $concept = $this->manager->fetchByUuid($uuid);

        if (!$concept) {
            throw new NotFoundException('Concept not found by id: ' . $uuid, 404);
        }

        if ($concept->isDeleted()) {
            throw new Exception\DeletedException('Concept ' . $uuid . ' is deleted', 410);
        }

        return $concept;
    }

    /**
     * Create the concept
     *
     * @param ServerRequestInterface $request
     */
    public function create(ServerRequestInterface $request)
    {
        try {
            $response = $this->handleCreate($request);
        } catch (ApiException $ex) {
            return $this->getErrorResponse($ex->getCode(), $ex->getMessage());
        }
        return $response;
    }

    /**
     * Handle the action of creating the concept
     *
     * @param ServerRequestInterface $request
     */
    private function handleCreate(ServerRequestInterface $request)
    {
        $xml = $request->getBody();
        if (!$xml) {
            throw new InvalidArgumentException('No RDF-XML recieved', 412);
        }

        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml)) {
            throw new InvalidArgumentException('Recieved RDF-XML is not valid XML', 412);
        }

        //do some basic tests
        if ($doc->documentElement->nodeName != 'rdf:RDF') {
            throw new InvalidArgumentException('Recieved RDF-XML is not valid: '
                    . 'expected <rdf:RDF/> rootnode, got <'.$doc->documentElement->nodeName.'/>', 412);
        }

        $descriptions = $doc->documentElement->getElementsByTagNameNs(Rdf::NAME_SPACE, 'Description');
        if ($descriptions->length != 1) {
            throw new InvalidArgumentException('Expected exactly one '
                    . '/rdf:RDF/rdf:Description, got '.$descriptions->length, 412);
        }

        $params = $request->getQueryParams();

        // is a tenant, collection or api key set in the XML?
        foreach (array('tenant', 'collection', 'key') as $attributeName) {
            $value = $doc->documentElement->getAttributeNS(OpenSkos::NAME_SPACE, $attributeName);
            if (!empty($value)) {
                $params[$attributeName] = $value;
            }
            // remove the api key
            if (!empty($value) && $attributeName === 'key') {
                $doc->documentElement->removeAttributeNS(OpenSkos::NAME_SPACE, $attributeName);
            }
        }

        $tenant = $this->getTenant($params);
        $collection = $this->getCollection($params, $tenant);
        $user = $this->getUser($params);

        $conceptXml = $descriptions->item(0);

        $data = array(
            'tenant' => $tenant->code,
            'collection' => $collection->id
        );

        $autoGenerateUri = $this->checkConceptIdentifiers($request, $conceptXml, $doc);

        $resource = (new Text($doc->saveXML()))->getResources();

        if (!isset($resource[0]) || !$resource[0] instanceof \OpenSkos2\Concept) {
            throw new InvalidArgumentException('XML Could not be converted to SKOS Concept', 400);
        }

        /** @var $concept \OpenSkos2\Concept **/
        $concept = $resource[0];
        if ($autoGenerateUri) {
            $concept->selfGenerateUri();
        }

        $concept->addProperty(Dc::DATE_SUBMITTED, new Literal(date('c'), null, Literal::TYPE_DATETIME));
        $concept->addProperty(DcTerms::CREATOR, $user->getFoafPerson());
        $concept->addProperty(DcTerms::CONTRIBUTOR, $user->getFoafPerson());

        if (!$this->uniquePrefLabel($concept)) {
            throw new InvalidArgumentException('The concept preflabel must be unique per scheme', 400);
        }
        
        $this->manager->insert($concept);
        return $this->getSuccessResponse($doc->saveXML());
    }

    /**
     * Get tenant
     *
     * @param array $params
     * @return \OpenSKOS_Db_Table_Row_Tenant
     */
    private function getTenant($params)
    {
        if (empty($params['tenant'])) {
            throw new InvalidArgumentException('No tenant specified', 412);
        }

        $tenantCode = $params['tenant'];
        $model = new \OpenSKOS_Db_Table_Tenants();
        $tenant = $model->find($tenantCode)->current();
        if (null === $tenant) {
            throw new InvalidArgumentException('No such tenant: `'.$tenantCode.'`', 404);
        }

        return $tenant;
    }

    /**
     * @params array $queryParams
     * @params \OpenSKOS_Db_Table_Row_Tenant $tenant
     * @return OpenSKOS_Db_Table_Row_Collection
     */
    private function getCollection($params, \OpenSKOS_Db_Table_Row_Tenant $tenant)
    {
        if (empty($params['collection'])) {
            throw new InvalidArgumentException('No collection specified', 412);
        }

        $model = new \OpenSKOS_Db_Table_Collections();
        $collection = $model->findByCode($params['collection'], $tenant);
        if (null === $collection) {
            throw new InvalidArgumentException('No such collection: `'.$collectionCode.'`', 404);
        }
        return $collection;
    }

    /**
     * @params array $queryParams
     * @return \OpenSKOS_Db_Table_Row_User
     */
    private function getUser($params)
    {
        if (empty($params['key'])) {
            throw new InvalidArgumentException('No key specified', 412);
        }

        $user = \OpenSKOS_Db_Table_Users::fetchByApiKey($params['key']);
        if (null === $user) {
            throw new InvalidArgumentException('No such API-key: `'.$apikey.'`', 401);
        }

        if (!$user->isApiAllowed()) {
            throw new InvalidArgumentException('Your user account is not allowed to use the API', 401);
        }

        if (strtolower($user->active) !== 'y') {
            throw new InvalidArgumentException('Your user account is blocked', 401);
        }

        return $user;
    }

    /**
     * Get error response
     *
     * @param integer $status
     * @param string $message
     * @return ResponseInterface
     */
    private function getErrorResponse($status, $message)
    {
        $stream = new Stream('php://memory', 'wb+');
        $stream->write($message);
        $response = (new Response($stream, $status, ['X-Error-Msg' => $message]));
        return $response;
    }

    /**
     * Get success response
     *
     * @param string $message
     * @return ResponseInterface
     */
    private function getSuccessResponse($message)
    {
        $stream = new Stream('php://memory', 'wb+');
        $stream->write($message);
        $response = (new Response($stream))
            ->withHeader('Content-Type', 'text/xml; charset="utf-8"');
        return $response;
    }

    /**
     * Check if we need to generate or not concept identifiers (notation and uri).
     * Validates any existing identifiers.
     *
     * @param $request \Psr\Http\Message\ServerRequestInterface
     * @param \DOMNode $description
     * @param \DOMDocument $doc
     * @return boolean If an uri must be autogenerated
     */
    private function checkConceptIdentifiers(ServerRequestInterface $request, \DOMNode $description, \DOMDocument $doc)
    {
        $params = $request->getQueryParams();

        // We return if an uri must be autogenerated
        $autoGenerateUri = false;
        $autoGenerateIdentifiers = false;
        if (!empty($params['autoGenerateIdentifiers'])) {
            $autoGenerateIdentifiers = filter_var(
                $params['autoGenerateIdentifiers'],
                FILTER_VALIDATE_BOOLEAN
            );
        }

        $xpath = new \DOMXPath($doc);
        $notationNodes = $xpath->query('skos:notation', $description);
        $uri = $description->getAttributeNS(Rdf::NAME_SPACE, 'about');

        if ($autoGenerateIdentifiers) {
            if ($uri || $notationNodes->length > 0) {
                throw new InvalidArgumentException(
                    'Parameter autoGenerateIdentifiers is set to true, but the '
                    . 'xml already contains notation (skos:notation) and/or uri (rdf:about).',
                    400
                );
            }
            $autoGenerateUri = true;
        } else {
            // Is uri missing
            if (!$uri) {
                throw new InvalidArgumentException(
                    'Uri (rdf:about) is missing from the xml. You may consider using autoGenerateIdentifiers.',
                    400
                );
            }

            // Is notation missing
            if ($notationNodes->length == 0) {
                throw new InvalidArgumentException(
                    'Notation (skos:notation) is missing from the xml. You may consider using autoGenerateIdentifiers.',
                    400
                );
            }

            // Is uri based on notation
            if (!\OpenSKOS_Db_Table_Notations::isContainedInUri($uri, $notationNodes->item(0)->nodeValue)) {
                throw new InvalidArgumentException(
                    'The concept uri (rdf:about) must be based on notation (must contain the notation)',
                    400
                );
            }

            $autoGenerateUri = false;
        }

        return $autoGenerateUri;
    }
    
    /**
     * Validate preflabel
     *
     * @param \OpenSkos2\Concept $concept
     * @return boolean
     */
    private function uniquePrefLabel(\OpenSkos2\Concept $concept)
    {
        $validator = new UniquePreflabelInScheme();
        $validator->setResourceManager($this->manager);
        return $validator->validate($concept);
    }
}