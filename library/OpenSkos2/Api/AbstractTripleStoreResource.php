<?php

namespace OpenSkos2\Api;

use DOMDocument;
use OpenSkos2\Api\Exception\ApiException;
use OpenSkos2\Api\Exception\InvalidPredicateException;
use OpenSkos2\Api\Exception\DeletedException;
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
use OpenSkos2\Api\Exception\InvalidArgumentException;
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
     * Deletion rules
     *
     * @var Deletion
     */
    protected $deletionIntegrityCheck;

    /**
     * array of custom.ini settings
     *
     * @var array
     */
    protected $customInit;

    /**
     * Amount of concepts to return
     *
     * @var int
     */
    protected $limit;

    private $parsedParameters;

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

        if (($context === 'json' || $context === 'jsonp') && $this->manager->getResourceType() === Tenant::TYPE) {
            $fieldname = 'sets';
            $extrasGraph = $this->manager->fetchSetsForTenantUri($resource->getUri());
            $extras = \OpenSkos2\Bridge\EasyRdf::graphToResourceCollection($extrasGraph);
        } else {
            $fieldname = null;
            $extras = [];
        }


        switch ($context) {
            case 'json':
                $detailJsonResponse = (new DetailJsonResponse($resource, $propertiesList));
                $detailJsonResponse->setExtras($extras, $fieldname, $this->customInit['backward_compatible']);
                $response = $detailJsonResponse->getResponse();
                break;
            case 'jsonp':
                $detailJsonPResponse = (new DetailJsonpResponse($resource, $params['callback'], $propertiesList));
                $detailJsonPResponse->setExtras($extras, $fieldname, $this->customInit['backward_compatible']);
                $response = $detailJsonPResponse->getResponse();
                break;
            case 'rdf':
                $response = (new DetailRdfResponse($resource, $propertiesList))->getResponse();
                break;
            default:
                throw new InvalidArgumentException('Invalid context: ' . $context);
        }
        return $response;
    }

    /*
     * Tests if string is a uuid
     * @param string $potentialUuid Thing that might be a Uuid
     * @return boolean True is it's a uuid else false
     */
    private function isUuid($potentialUuid)
    {
		/* Seemingly Beeld en Geluid do not use RFC compliant UUID's*/
        //$res = preg_match('#^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$#i', $potentialUuid);
		$res = preg_match('#^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$#i', $potentialUuid);
		return $res;
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
        $rdfType = $this->manager->getResourceType();

        if ($id instanceof Uri) {
            $resource = $this->manager->fetchByUri($id, $rdfType);
        } elseif ($this->isUuid($id)) {
            $resource = $this->manager->fetchByUuid($id, $rdfType);
        } else {
            //Previous versions of OpenSkos allowed fetch by notation; Beng used it!!!!
            //  No agreement ever made we would deprecate this feature
            $fullNotatationPredicate = sprintf("<%s>", \OpenSkos2\Namespaces\Skos::NOTATION);
            $resource = $this->manager->fetchByUuid($id, $rdfType, $fullNotatationPredicate);
        }

        if (!$resource) {
            throw new NotFoundException("Resource not found by uri/uuid: $id \n: ", 404);
        }
        return $resource;
    }

    public function getResourceListResponse($params)
    {

        try {
            $index = $this->getResourceList($params);


            $result = new ResourceResultSet(
                $index,
                count($index),
                1,
                $this->customInit['maximal_rows']
            );

            switch ($params['context']) {
                case 'json':
                    $jsonResponse = (new JsonResponse($result));
                    $jsonResponse->setInit($this->customInit);
                    $response = $jsonResponse->getResponse();
                    break;
                case 'jsonp':
                    $jsonPResponse = (new JsonpResponse($result, $params['callback']));
                    $jsonPResponse->setInit($this->customInit);
                    $response = $jsonPResponse->getResponse();
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
        $params = $this->getParams($request);
        $parsedBody = $request->getParsedBody();

        $apiKey = $this->getApiKey($request);

        $user = $this->getUserByKey($apiKey);
        $tenant = $this->getTenantFromApiCall($request, $user);

        $resource = $this->getResourceFromRequest($request, $tenant);

        if ($resource->isBlankNode()) {
            return $this->getErrorResponse(400, 'Uri (rdf:about) is missing from the xml. Try POST.');
        }

        if (!$this->manager->askForUri((string) $resource->getUri())) {
            return $this->getErrorResponse(404, 'Resource not found, try POST.');
        }

        try {
            $existingResource = $this->manager->fetchByUri((string) $resource->getUri());

            $set = $this->getSet($request);

            $user = $this->getUserFromParams($params);

            $authorisation = $this->manager->getAuthorisationObject();
            if (!empty($authorisation)) {
                $authorisation->resourceEditAllowed($user, $tenant, $set, $resource);
            }

            if ($resource instanceof \OpenSkos2\Concept) {
                $this->checkConceptXl($resource, $tenant);
            }


            $resource->ensureMetadata(
                $tenant,
                $set,
                $user->getFoafPerson(),
                $this->personManager,
                $this->manager->getLabelManager(),
                $existingResource
            );

            $this->validate($resource, $tenant, $set, true);

            if ($this->manager->getResourceType() === \OpenSkos2\Concept::TYPE) {
                $this->manager->replaceAndCleanRelations($resource);
            } else {
                $this->manager->replace($resource);
            }
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
            $parsedBody = $request->getParsedBody();

            if (empty($params['id'])) {
                throw new InvalidArgumentException('Missing id parameter', 400);
            }

            $id = $params['id'];
            /* @var $resource  */
            $resource = $this->manager->fetchByUri($id);
            if (!$resource) {
                throw new NotFoundException('Concept not found by id :' . $id, 404);
            }

            $apiKey = $this->getApiKey($request);
            $user = $this->getUserByKey($apiKey);

            $tenant = $this->getTenantFromApiCall($request, $user);

            $set = $this->getSet($request);

            $authorisation = $this->manager->getAuthorisationObject();
            if (!empty($authorisation)) {
                $authorisation->resourceDeleteAllowed($user, $tenant, $set, $resource);
            }

            $this->deletionIntegrityCheck->canBeDeleted($id);

            if ($resource->getType()->getUri() === \OpenSkos2\Concept::TYPE) {
                if ($resource->isDeleted()) {
                    throw new DeletedException('Concept already deleted :' . $id, 410);
                }
                $this->manager->deleteSoft($resource);
                $response = $this->getSuccessResponse($this->loadResourceToRdf($resource), 202);
            } else {
                $this->manager->delete($resource);
                $response = $this->getSuccessResponse($resource, 202);
            }

// amounts to full delete for non-concept resources
        } catch (ApiException $ex) {
            return $this->getErrorResponse($ex->getCode(), $ex->getMessage());
        }

        return $response;
    }

    /**
     * Loads the resource from the db and transforms it to rdf.
     * @param $resource
     * @return string
     */
    protected function loadResourceToRdf($resource)
    {

        $loadedResource = $this->manager->fetchByUri($resource->getUri());


        //POST MM terms will have a publisher URI
        $publisherUri = $loadedResource->getPublisherUri();

        $tenant = $this->manager->fetchByUri($publisherUri, \OpenSkos2\Tenant::TYPE);

        if ($loadedResource instanceof \OpenSkos2\Concept && $tenant->isEnableSkosXl()) {
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
            $this->manager,
            $tenant,
            $set,
            $isForUpdate,
            true
        );
        if (!$validator->validate($resource)) {
            $errorCode = 400;
            if (method_exists($validator, "getErrorCodes")) {
                $someCodes = $validator->getErrorCodes();
                if (count($someCodes) > 0) {
                    $errorCode = $validator->getErrorCodes()[0];
                }
            }

            throw new InvalidArgumentException(implode(' ', $validator->getErrorMessages()), $errorCode);
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
        $params = $this->getParams($request);

        $apiKey = $this->getApiKey($request);

        $user = $this->getUserByKey($apiKey);

        $tenant = $this->getTenantFromApiCall($request, $user);

        $resource = $this->getResourceFromRequest($request, $tenant);

        if (!$resource->isBlankNode() && $this->manager->askForUri((string) $resource->getUri())) {
            throw new InvalidArgumentException(
                'The concept with uri ' . $resource->getUri() . ' already exists. Use PUT instead.',
                400
            );
        }

        $set = $this->getSet($request);

        $user = $this->getUserFromParams($params);

        if ($resource instanceof \OpenSkos2\Concept) {
            $this->checkConceptXl($resource, $tenant);
        }

        $resource->ensureMetadata(
            $tenant,
            $set,
            $user->getFoafPerson(),
            $this->personManager,
            $this->manager->getLabelManager()
        );

        $authorisation = $this->manager->getAuthorisationObject();
        if (!empty($authorisation)) {
            $authorisation->resourceCreateAllowed($user, $tenant, $set, $resource);
        }

        $autoGenerateUri = $this->checkResourceIdentifiers($request, $resource);

        if ($autoGenerateUri) {
            $resource->selfGenerateUri(
                $tenant,
                $set,
                $this->manager
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

        if ($this->customInit['backward_compatible']) {
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
     * @param Tenant $tenant |  null (if tenant is created)
     * @return \OpenSkos2\*
     * @throws InvalidArgumentException
     */
    protected function getResourceFromRequest(ServerRequestInterface $request, $tenant)
    {
        $doc = $this->getDomDocumentFromRequest($request);

        // remove the api key
        $doc->documentElement->removeAttributeNS(OpenSkos::NAME_SPACE, 'key');

        $rdfType = $this->manager->getResourceType();

        $resources = (new Text($doc->saveXML()))->getResources($rdfType, \OpenSkos2\Concept::$classes['SkosXlLabels']);

        if ($resources->count() != 1) {
            throw new InvalidArgumentException(
                "Expected exactly one resource of type $rdfType, got {$resources->count()}, "
                . "check if you set rdf:type in the request body, " . $resources->count(),
                412
            );
        }

        $resource = $resources[0];

        $className = Namespaces::mapRdfTypeToClassName($rdfType);
        if (!isset($resource) || !$resource instanceof $className) {
            $actualClassName = get_class($resource);
            throw new InvalidArgumentException("XML Could not be converted to $className, "
            . "it is an instance of $actualClassName", 400);
        }

        if ($this->manager->getResourceType() !== \OpenSkos2\Tenant::TYPE) {
            // Is a tenant in the custom openskos xml attributes but not in the rdf add the values to the concept
            $xmlTenant = $doc->documentElement->getAttributeNS(OpenSkos::NAME_SPACE, 'tenant');
            if (!$resource->getTenant() && !empty($xmlTenant)) {
                $resource->addUniqueProperty(OpenSkos::TENANT, new \OpenSkos2\Rdf\Literal($xmlTenant));
            }
            // If there still is no tenant add it from the query params
            if (!$resource->getTenant()) {
                $resource->addUniqueProperty(OpenSkos::TENANT, $tenant->getCode());
            }
            // overkill check
            if (!$resource->getTenant()) {
                throw new InvalidArgumentException('No tenant specified', 400);
            }
            $rdfTenant = $this->manager->fetchByUuid($resource->getTenant(), \OpenSkos2\Tenant::TYPE, 'openskos:code');
            $resource->setProperty(Namespaces\DcTerms::PUBLISHER, new \OpenSkos2\Rdf\Uri($rdfTenant->getUri())); // within the triple store resources are referred via URI's not literals, we keep literals for API backward compatibility and convenience
        }

        return $resource;
    }

    /**
     * Get dom document from request
     *
     * @param ServerRequestInterface $request
     * @return DOMDocument
     * @throws InvalidArgumentException
     */
    protected function getDomDocumentFromRequest(ServerRequestInterface $request)
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
                . 'expected <rdf:RDF/> rootnode, got <' . $doc->documentElement->nodeName . '/>',
                400
            );
        }

        return $doc;
    }

    /**
     * @param $params
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @throws InvalidArgumentException
     */
    protected function getSet($request)
    {
        $set = null;

        if ($this->customInit['backward_compatible']) {
            $setName = 'collection';
        } else {
            $setName = 'set';
        }


        $code = $this->getMultiSourcedParameter($request, $setName);
        if ($code) {
            $set = $this->manager->fetchByUuid($code, OpenSkos::SET, 'openskos:code');

            if (!isset($set)) {
                throw new InvalidArgumentException(
                    "No such $setName `$code`",
                    404
                );
            }
        }
        return $set;
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
        $autoGenerateIdentifiers = $this->getMultiSourcedParameter($request, 'autoGenerateIdentifiers');

        // We return if an uri must be autogenerated
        if (isset($autoGenerateIdentifiers) &&
            ($autoGenerateIdentifiers === '1' || strtolower($autoGenerateIdentifiers) === 'true'
                || strtolower($autoGenerateIdentifiers) == 'y' || strtolower($autoGenerateIdentifiers) == 'yes')) {
            $autoGenerateIdentifiers = true;
        } else {
            $autoGenerateIdentifiers = false;
        }

        if ($autoGenerateIdentifiers) {
            if (!$resource->isBlankNode()) {
                throw new InvalidArgumentException(
                    'Parameter autoGenerateIdentifiers is set to true, but the '
                    . 'xml already contains uri (rdf:about).',
                    400
                );
            }
        } else {
            // Is uri missing
            if ($resource->isBlankNode()) {
                throw new InvalidArgumentException(
                    'Uri (rdf:about) is missing from the xml. You may consider using autoGenerateIdentifiers.',
                    400
                );
            }
        }

        return $autoGenerateIdentifiers;
    }

    /*
     * We have dreamt up many ways of sending some parameters
     * This unfortunate function tries to work out which one is being used here
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return string the parameter tried to get
     */
    protected function getMultiSourcedParameter($request, $parameter)
    {
        if ($this->parsedParameters) {
            return $this->parsedParameters[$parameter];
        }

        $lookups = array(
            'tenant',
            'collection',
            'set',
            'key',
            'autoGenerateIdentifiers'
        ); //Values we're trying to look up

        $params = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();
        $xmlParameters = array();

        //Drag these out of the DOM
        $doc = $this->getDomDocumentFromRequest($request);
        // is a tenant, collection or api key set in the XML?
        foreach ($lookups as $attributeName) {
            $value = $doc->documentElement->getAttributeNS(OpenSkos::NAME_SPACE, $attributeName);
            if (!empty($value)) {
                $xmlParameters[$attributeName] = $value;
            }
        }

        foreach ($lookups as $prm) {
            $foundVal = false;

            if (isset($xmlParameters[$prm]) && $xmlParameters[$prm]) {
                //Let's try the XML first
                $foundVal = $xmlParameters[$prm];
            } elseif (isset($params[$prm]) && $params[$prm]) {
                //Nope! Then the Query string
                $foundVal = $params[$prm];
            } elseif (isset($parsedBody[$prm]) && $parsedBody[$prm]) {
                //Nope! Then the POST BODY
                $foundVal = $parsedBody[$prm];
            } elseif ($request->getHeader($prm)) {
                //Nope! Then the header parameters
                $foundVal = $request->getHeader($prm)[0];
            }

            $this->parsedParameters[$prm] = $foundVal;
        }

        return $this->parsedParameters[$parameter];
    }


    /**
     * Work out active tenant for this api call.
     *   Could be passed either in the URI, or derived from the user's api key in the request body
     * @param ServerRequestInterface $request
     * @param OpenSKOS_Db_Table_Row_User user derived from apiKey
     * @return \OpenSkos2\Tenant Active tenant
     */
    protected function getTenantFromApiCall(ServerRequestInterface $request, $user)
    {
        $paramTenant = $this->getMultiSourcedParameter($request, 'tenant');

        if ($paramTenant) {
            $tenant = $this->getTenant($paramTenant, $this->manager);
        } else {
            throw new InvalidArgumentException('No tenant specified or no tenant in an accepted format', 400);

            /*
             * Some older version of OpenSkos would call up the tenant from the user's API key, if none was readable
             *   from the API call.
             *
             * Currently agreed with Beeld and Geluid to throw a 400 instead,
             *   but the old behaviour is easy to restore if desired.
             *

                $paramTenant = $user->tenant;
                $tenant = $this->getTenant($paramTenant, $this->manager);
             */
        }
        return $tenant;
    }

    /**
     *
     * @param array $params
     * @return OpenSKOS_Db_Table_Row_User
     * @throws InvalidArgumentException
     */
    protected function getUserFromParams($params)
    {
        if (empty($params['key'])) {
            throw new InvalidArgumentException('No key specified', 400);
        }
        return $this->getUserByKey($params['key']);
    }

    protected function getRequiredParameters()
    {

        if ($this->customInit['backward_compatible']) {
            $setName = 'collection';
        } else {
            $setName = 'set';
        }

        return ['key', 'tenant', $setName];
    }

    public function mapNameSearchID()
    {
        $index = $this->manager->fetchNameSearchID();
        return $index;
    }

    public function mapNameURI()
    {
        $index = $this->manager->fetchNameUri();
        return $index;
    }

    public function getResourceManager()
    {

        return $this->manager;
    }
}
