<?php

namespace OpenSkos2\Api;

use DOMDocument;
use OpenSkos2\Api\Exception\ApiException;
use OpenSkos2\Api\Exception\InvalidPredicateException;
use OpenSkos2\Api\Exception\NotFoundException;
use OpenSkos2\Api\Response\Detail\JsonpResponse as DetailJsonpResponse;
use OpenSkos2\Api\Response\Detail\JsonResponse as DetailJsonResponse;
use OpenSkos2\Api\Response\Detail\RdfResponse as DetailRdfResponse;
use OpenSkos2\Api\Response\ResultSet\JsonResponse;
use OpenSkos2\Api\Response\ResultSet\JsonpResponse;
use OpenSkos2\Api\Response\ResultSet\RdfResponse;
use OpenSkos2\FieldsMaps;
use OpenSkos2\Rdf\Uri;
use OpenSkos2\Set;
use OpenSkos2\Tenant;
use OpenSkos2\Converter\Text;
use OpenSkos2\Namespaces;
use OpenSkos2\Namespaces\OpenSkos;
use OpenSkos2\Validator\Resource as ResourceValidator;
use OpenSKOS_Db_Table_Row_User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Solarium\Exception\InvalidArgumentException;
use Zend\Diactoros\Response;
use Zend\Diactoros\Stream;

abstract class AbstractTripleStoreResource
{

    use \OpenSkos2\Api\Response\ApiResponseTrait;

    /**
     * Person manager
     *
     * @var PersonManager
     */
    protected $personManager;

    /*
     * 
     * @var TenantManager|SetManager|ConceptSchemeManager|SkosCollectionManager|ConceptManager|RelationTypeManager|RelationManager
     */
    protected $manager;

    /**
     * Authorisation rules
     * 
     * @var Authorisation
     */
    protected $authorisation;

    /**
     * Deletion rules
     * 
     * @var Deletion
     */
    protected $deletion;

    /**
     * array of application.ini settings
     * 
     * @var init
     */
    protected $init;

    /**
     * Get PSR-7 response for resource
     *
     * @param $request \Psr\Http\Message\ServerRequestInterface
     * @param string $context
     * @throws NotFoundException
     * @throws InvalidArgumentException
     * @return ResponseInterface
     */
    public function getResourceResponse(ServerRequestInterface $request, $id, $context)
    {
        $resource = $this->getResource($id);

        $params = $request->getQueryParams();

        if (isset($params['fl'])) {
            $propertiesList = $this->fieldsListToProperties($params['fl']);
        } else {
            $propertiesList = [];
        }


        switch ($context) {
            case 'json':
                $response = (new DetailJsonResponse($resource, $propertiesList, null))->getResponse();
                break;
            case 'jsonp':
                $response = (new DetailJsonpResponse($resource, $params['callback'], $propertiesList, null))->getResponse();
                break;
            case 'rdf':
                $response = (new DetailRdfResponse($resource, $propertiesList, null))->getResponse();
                break;
            default:
                throw new InvalidArgumentException('Invalid context: ' . $context);
        }
        return $response;
    }

    /**
     * Get openskos resource
     *
     * @param string|Uri $id
     * @throws NotFoundException
     * @return a sublcass of \OpenSkos2\Resource
     */
    public function getResource($id)
    {
        if ($id instanceof Uri) {
            $resource = $this->manager->fetchByUri($id);
        } else {
            try {
                $resource = $this->manager->fetchByUuid($id);
            } catch (\OpenSkos2\Exception\ResourceNotFoundException $e) {
                $resource = $this->manager->fetchByUuid($id, 'openskos:code');
            }
        }

        if (!$resource) {
            throw new NotFoundException('Resource not found by uri/uuid: ' . $id, 404);
        }
        return $resource;
    }

    public function getResourceListResponse($params)
    {

        try {

            $index = $this->getResourceList($params);


            $result = new ResourceResultSet(
                $index, count($index), 1, $this->init["custom.maximal_rows"]
            );

            switch ($params['context']) {
                case 'json':
                    $response = (new JsonResponse($result))->getResponse();
                    break;
                case 'jsonp':
                    $response = (new JsonpResponse(
                        $result, $params['callback']))->getResponse();
                    break;
                case 'rdf':
                    $response = (new RdfResponse($result))->getResponse();
                    break;
                default:
                    throw new InvalidArgumentException('Invalid context: ' . $params['context']);
            }
            return $response;
        } catch (\Exception $e) {
            return $this->getErrorResponse(500, $e->getMessage());
        }
    }

    private function getResourceList($params)
    {
        $resType = $this->manager->getResourceType();
        if ($resType === Set::TYPE && $params['allow_oai'] !== null) {
            $index = $this->manager->fetchAllSets($params['allow_oai']);
        } else {
            $index = $this->manager->fetch();
        }
        return $index;
    }

    /**
     * Create the resource
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
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
     * Update a resource
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function update(ServerRequestInterface $request)
    {
        $resource = $this->getResourceFromRequest($request);

        if (!$this->manager->askForUri((string) $resource->getUri())) {
            return $this->getErrorResponse(404, 'Resource not found try insert');
        }

        try {
            $existingResource = $this->manager->fetchByUri((string) $resource->getUri());

            $params = $this->getParams($request);

            $tenant = $this->getTenantFromParams($params);

            $set = $this->getSetFromParams($params);

            $user = $this->getUserFromParams($params);

            $this->authorisation->resourceEditAllowed($user, $resource->getInstitution(), $set, $resource);

            $resource->ensureMetadata(
                $tenant, $set, $user->getFoafPerson(), $this->manager->getLabelManager(), $this->personManager, $existingResource);

            $this->validate($resource, $tenant, $set, true);

            $this->manager->replaceAndCleanRelations($resource);
        } catch (ApiException $ex) {
            return $this->getErrorResponse($ex->getCode(), $ex->getMessage());
        }

        return $this->getSuccessResponse($this->loadResourceToRdf($resource));
    }

    /**
     * Perform a soft delete on a resource
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     * @throws InvalidArgumentException
     * @throws NotFoundException
     */
    public function delete(ServerRequestInterface $request)
    {
        try {
            $params = $request->getQueryParams();

            if (empty($params['id'])) {
                throw new InvalidArgumentException('Missing id parameter');
            }

            $id = $params['id'];
            /* @var $resource  */
            $resource = $this->manager->fetchByUri($id);
            if (!$resource) {
                throw new NotFoundException('Concept not found by id :' . $id, 404);
            }

            if ($resource->isDeleted()) {
                throw new NotFoundException('Concept already deleted :' . $id, 410);
            }
            $user = $this->getUserFromParams($params);

            $set = $this->getSetFromParams($params);

            $this->authorisation->resourceDeleteAllowed($user, $resource->getInstitution(), $set, $resource);

            $this->authorisation->canBeDeleted($id); // default: must not contain references to other resources down in the hierarchy

            $this->manager->deleteSoft($resource); // amounts to full delete for non-concept resources
        } catch (ApiException $ex) {
            return $this->getErrorResponse($ex->getCode(), $ex->getMessage());
        }
        return $this->getSuccessResponse($this->loadResourceToRdf($resource), 202);
    }

    /**
     * Loads the resource from the db and transforms it to rdf.
     * @param Resource $resource
     * @return string
     */
    protected function loadResourceToRdf(Resource $resource)
    {
        $loadedResource = $this->manager->fetchByUri($resource->getUri());

        $tenant = $this->manager->fetchByUri($loadedResource->getTenantUri(), Tenant::TYPE);

        if ($loadedResource instanceof \OpenSkos2\Concept && $tenant->getEnableSkosXl()) {
            $loadedResource->loadFullXlLabels($this->manager->getLabelManager());
        }

        return (new Transform\DataRdf($loadedResource))->transform();
    }

    /**
     * Gets a list (array or string) of fields and try to recognise the properties from it.
     * @param array $fieldsList
     * @return array
     * @throws InvalidPredicateException
     */
    protected function fieldsListToProperties($fieldsList)
    {
        if (!is_array($fieldsList)) {
            $fieldsList = array_map('trim', explode(',', $fieldsList));
        }
        $propertiesList = [];
        $fieldsMap = FieldsMaps::getNamesToProperties();
        // Tries to search for the field in fields map.
        // If not found there tries to expand it from short property.
        // If not that - just pass it as it is.
        foreach ($fieldsList as $field) {
            if (!empty($field)) {
                if (isset($fieldsMap[$field])) {
                    $propertiesList[] = $fieldsMap[$field];
                } else {
                    $propertiesList[] = Namespaces::expandProperty($field);
                }
            }
        }
        // Check if we have a nice properties list at the end.
        foreach ($propertiesList as $propertyUri) {
            if ($propertyUri == 'uri') {
                continue;
            }
            if (filter_var($propertyUri, FILTER_VALIDATE_URL) == false) {
                throw new InvalidPredicateException(
                'The field "' . $propertyUri . '" from fields list is not recognised.'
                );
            }
        }
        return $propertiesList;
    }

    /**
     * Applies all validators to the concept.
     * @param \OpenSkos2\Resource $resource
     * @param Tenant|null $tenant   
     * @param Set|null $set
     * @param bool $isForUpdate
     * @throws InvalidArgumentException
     */
    protected function validate($resource, $tenant, $set, $isForUpdate)
    {
        // the last parameter switches check if the referred within the resource objects do exists in the triple store
        $validator = new ResourceValidator(
            $this->manager, $tenant, $set, $isForUpdate, true
        );
        if (!$validator->validate($resource)) {
            throw new InvalidArgumentException(implode(' ', $validator->getErrorMessages()), 400);
        }
    }

    /**
     * Handle the action of creating the concept
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     * @throws InvalidArgumentException
     */
    private function handleCreate(ServerRequestInterface $request)
    {
        $resource = $this->getConceptFromRequest($request);

        if (!$resource->isBlankNode() && $this->manager->askForUri((string) $resource->getUri())) {
            throw new InvalidArgumentException(
            'The concept with uri ' . $resource->getUri() . ' already exists. Use PUT instead.', 400
            );
        }

        $params = $this->getParams($request);

        $tenant = $this->getTenantFromParams($params);

        $set = $this->getSet($params, $tenant, $resource);

        $user = $this->getUserFromParams($params);

        if ($resource instanceof \OpenSkos2\Concept) {
            $this->checkConceptXl($resource, $tenant);
        }

        $resource->ensureMetadata(
            $tenant, $set, $user->getFoafPerson(), $this->manager->getLabelManager(), $this->personManager
        );

        $autoGenerateUri = $this->checkResourceIdentifiers($request, $resource);

        if ($autoGenerateUri) {
            $resource->selfGenerateUri(
                $tenant, $this->manager
            );
        }

        $this->validate($resource, $tenant, $set, false);

        $this->manager->insert($resource);

        return $this->getSuccessResponse($this->loadResourceToRdf($resource), 201);
    }

    /**
     * Get request params, including parameters send in XML body
     *
     * @param ServerRequestInterface $request
     * @return array
     */
    private function getParams(ServerRequestInterface $request)
    {
        $params = $request->getQueryParams();
        $doc = $this->getDomDocumentFromRequest($request);

        // is a tenant, collection or api key set in the XML?

        if ($this->init['custom.backward_compatible']) {
            $set = 'collection';
        } else {
            $set = 'set';
        }

        $required = $this->getRequiredParameters();

        foreach ($required as $attributeName) {
            $value = $doc->documentElement->getAttributeNS(OpenSkos::NAME_SPACE, $attributeName);
            if (!empty($value)) {
                $params[$attributeName] = $value;
            }
        }
        return $params;
    }

    /**
     * Get the resource from the request to insert or update
     * does some validation to see if the xml is valid
     *
     * @param ServerRequestInterface $request
     * @param Tenant $tenant
     * @return \OpenSkos2\*
     * @throws InvalidArgumentException
     */
    protected function getResourceFromRequest(ServerRequestInterface $request, $tenant)
    {
        $doc = $this->getDomDocumentFromRequest($request);

        // remove the api key
        $doc->documentElement->removeAttributeNS(OpenSkos::NAME_SPACE, 'key');

        $rdfType = $this->manager->getResourceType();

        $resource = (new Text($doc->saveXML()))->getResources($rdfType, \OpenSkos2\Concept::$classes['SkosXlLabels']);

        if ($resource->count() != 1) {
            throw new InvalidArgumentException(
            'Expected exactly one concept, got ' . $resource->count(), 412
            );
        }

        $className = Namespaces::mapRdfTypeToClassName($rdfType);
        if (!isset($resource) || !$resource instanceof $className) {
            throw new InvalidArgumentException('XML Could not be converted to ' . $rdfType, 400);
        }

        // tenant must be supplied only for OpenSkos2\Set objects, see the corresponding child class
        // for scheme's the tenant is derivable from its set
        // for concepts the tenant is derivable via its scheme via its set.

        return $resource[0];
    }

    /**
     * Get dom document from request
     *
     * @param ServerRequestInterface $request
     * @return DOMDocument
     * @throws InvalidArgumentException
     */
    private function getDomDocumentFromRequest(ServerRequestInterface $request)
    {
        $xml = $request->getBody();
        if (!$xml) {
            throw new InvalidArgumentException('No RDF-XML recieved', 400);
        }
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml)) {
            throw new InvalidArgumentException('Recieved RDF-XML is not valid XML', 400);
        }
        //do some basic tests
        if ($doc->documentElement->nodeName != 'rdf:RDF') {
            throw new InvalidArgumentException(
            'Recieved RDF-XML is not valid: '
            . 'expected <rdf:RDF/> rootnode, got <' . $doc->documentElement->nodeName . '/>', 400
            );
        }
        return $doc;
    }

    /**
     * @param $params
     * @param Tenant $tenant
     * @return Set
     * @throws InvalidArgumentException
     */
    protected function getSet($params, $tenant)
    {
        if ($this->init['custom.backward_compatible']) {
            $setName = 'collection';
        } else {
            $setName = 'set';
        }

        if (empty($params[$setName])) {
            throw new InvalidArgumentException("No $setName specified in the request parameters", 400);
        }

        $code = $params[$setName];
        $set = $this->manager->fetchByCode($code, Set::TYPE);
        if (null === $set) {
            throw new InvalidArgumentException(
            "No such $setName `$code`", 404
            );
        }
    }

    /**
     * Removed getErrorResponse function definition because it is already declared in ApiResponseTrait
     */

    /**
     * Get success response
     *
     * @param string $message
     * @param int    $status
     * @return ResponseInterface
     */
    private function getSuccessResponse($message, $status = 200)
    {
        $stream = new Stream('php://memory', 'wb+');
        $stream->write($message);
        $response = (new Response($stream, $status))
            ->withHeader('Content-Type', 'text/xml; charset="utf-8"');
        return $response;
    }

    /**
     * Check if we need to generate or not concept identifiers (uri).
     * Validates any existing identifiers.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @param \OpenSkos2\Resource $resource
     * @return bool If an uri must be autogenerated
     * @throws InvalidArgumentException
     */
    private function checkResourceIdentifiers(ServerRequestInterface $request, $resource)
    {
        $params = $request->getQueryParams();

        // We return if an uri must be autogenerated
        $autoGenerateIdentifiers = false;
        if (!empty($params['autoGenerateIdentifiers'])) {
            $autoGenerateIdentifiers = filter_var(
                $params['autoGenerateIdentifiers'], FILTER_VALIDATE_BOOLEAN
            );
        }

        if ($autoGenerateIdentifiers) {
            if (!$resource->isBlankNode()) {
                throw new InvalidArgumentException(
                'Parameter autoGenerateIdentifiers is set to true, but the '
                . 'xml already contains uri (rdf:about).', 400
                );
            }
        } else {
            // Is uri missing
            if ($resource->isBlankNode()) {
                throw new InvalidArgumentException(
                'Uri (rdf:about) is missing from the xml. You may consider using autoGenerateIdentifiers.', 400
                );
            }
        }

        return $autoGenerateIdentifiers;
    }

    /**
     * @param array $params
     * @return Tenanthrows InvalidArgumentException
     */
    protected function getTenantFromParams($params)
    {
        if (empty($params['tenant'])) {
            throw new InvalidArgumentException('No tenant specified', 400);
        }

        return $this->getTenant($params['tenant'], $this->manager);
    }

    /**
     *
     * @param array $params
     * @return OpenSKOS_Db_Table_Row_User
     * @throws InvalidArgumentException
     */
    private function getUserFromParams($params)
    {
        if (empty($params['key'])) {
            throw new InvalidArgumentException('No key specified', 400);
        }
        return $this->getUserByKey($params['key']);
    }

    /*   Returns a mapping resource's titles to the resource's Uri
     *   Works for tenant, set, concept scheme, skos colllection, relation type
     */

    public function mapNameSearchID()
    {
        $index = $this->manager->fetchNameUri();
        return $index;
    }

    protected function getRequiredParameters()
    {

        if ($this->init['custom.backward_compatible']) {
            $setName = 'collection';
        } else {
            $setName = 'set';
        }

        return ['key', 'tenant', $setName];
    }

}
