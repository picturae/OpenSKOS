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

use OpenSkos2\Api\Exception\NotFoundException;
use OpenSkos2\Namespaces;
use OpenSkos2\Namespaces\OpenSkos;
use OpenSKOS_Db_Table_Row_Collection;
use OpenSkos2\Api\Exception\InvalidArgumentException;
use OpenSkos2\Api\Response\ResultSet\JsonResponse;
use OpenSkos2\Api\Response\ResultSet\JsonpResponse;
use OpenSkos2\Api\Response\ResultSet\RdfResponse;
use OpenSkos2\Api\Response\Detail\JsonResponse as DetailJsonResponse;
use OpenSkos2\Api\Response\Detail\JsonpResponse as DetailJsonpResponse;
use OpenSkos2\Api\Response\Detail\RdfResponse as DetailRdfResponse;
use OpenSkos2\Api\Exception\InvalidPredicateException;
use OpenSkos2\Rdf\ResourceManager;
use OpenSkos2\FieldsMaps;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Diactoros\Response;
use Zend\Diactoros\Stream;

/**
 * Map an API request from the old application to still work with the new backend on Jena
 */
class Resource
{
    use \OpenSkos2\Api\Response\ApiResponseTrait;

    const QUERY_DESCRIBE = 'describe';
    const QUERY_COUNT = 'count';

    /**
     * Resource manager
     *
     * @var \OpenSkos2\Rdf\ResourceManager
     */
    protected $manager;

    /**
     * Search autocomplete
     *
     * @var \OpenSkos2\Search\Autocomplete
     */
    protected $searchAutocomplete;

    /**
     * Amount of resources to return
     *
     * @var int
     */
    protected $limit = 20;

    /**
     *
     * @param \OpenSkos2\Rdf\ResourceManager $manager
     * @param \OpenSkos2\Search\Autocomplete $searchAutocomplete
     */
    public function __construct(
        ResourceManager $manager,
        \OpenSkos2\Search\Autocomplete $searchAutocomplete
    ) {
        $this->manager = $manager;
        $this->searchAutocomplete = $searchAutocomplete;
    }
    
    /**
     * Create the resource
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function create(ServerRequestInterface $request)
    {
        // @TODO Currently implemented for concepts only. Implement for resource.
        throw new \BadMethodCallException('Not implemented');
    }

    /**
     * Update a resource
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function update(ServerRequestInterface $request)
    {
        // @TODO Currently implemented for concepts only. Implement for resource.
        throw new \BadMethodCallException('Not implemented');
    }

    /**
     * Perform a soft delete on a resource
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     * @throws InvalidArgumentException
     * @throws NotFoundException
     */
    public function delete(ServerRequestInterface $request)
    {
        // @TODO Currently implemented for concepts only. Implement for resource.
        throw new \BadMethodCallException('Not implemented');
    }

    /**
     * Map the following requests
     *
     * /api/index?q=Kim%20Holland
     * /api/index?&fl=prefLabel,scopeNote&format=json&q=inScheme:"http://uri"
     * /api/index?format=json&fl=uuid,uri,prefLabel,class,dc_title&id=http://data.beeldengeluid.nl/gtaa/27140
     * /api/resource/82c2614c-3859-ed11-4e55-e993c06fd9fe.rdf
     *
     * @param ServerRequestInterface $request
     * @param string $context
     * @return ResponseInterface
     * @throws InvalidArgumentException
     */
    public function find(ServerRequestInterface $request, $context)
    {
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
        
        $resources = $this->searchAutocomplete->search($this->getSearchOptions($params, $start, $limit), $total);

        $result = new ResourceResultSet($resources, $total, $start, $limit);

        if (isset($params['fl'])) {
            $propertiesList = $this->fieldsListToProperties($params['fl']);
        } else {
            $propertiesList = [];
        }

        switch ($context) {
            case 'json':
                $response = (new JsonResponse($result, $propertiesList))->getResponse();
                break;
            case 'jsonp':
                $response = (new JsonpResponse($result, $params['callback'], $propertiesList))->getResponse();
                break;
            case 'rdf':
                $response = (new RdfResponse($result, $propertiesList))->getResponse();
                break;
            default:
                throw new InvalidArgumentException('Invalid context: ' . $context);
        }

        return $response;
    }
    
    /**
     * Get PSR-7 response for resource
     *
     * @param $request \Psr\Http\Message\ServerRequestInterface
     * @param string $context
     * @throws NotFoundException
     * @throws InvalidArgumentException
     * @return ResponseInterface
     */
    public function getResponse(ServerRequestInterface $request, $id, $context)
    {
        $resource = $this->get($id);

        $params = $request->getQueryParams();

        if (isset($params['fl'])) {
            $propertiesList = $this->fieldsListToProperties($params['fl']);
        } else {
            $propertiesList = [];
        }

        switch ($context) {
            case 'json':
                $response = (new DetailJsonResponse($resource, $propertiesList))->getResponse();
                break;
            case 'jsonp':
                $response = (new DetailJsonpResponse($resource, $params['callback'], $propertiesList))->getResponse();
                break;
            case 'rdf':
                $response = (new DetailRdfResponse($resource, $propertiesList))->getResponse();
                break;
            default:
                throw new InvalidArgumentException('Invalid context: ' . $context);
        }

        return $response;
    }

    /**
     * Get openskos resource
     *
     * @param string|\OpenSkos2\Rdf\Uri $id
     * @throws NotFoundException
     * @throws Exception\DeletedException
     * @return \OpenSkos2\Rdf\Resource
     */
    public function get($id)
    {
        if ($id instanceof \OpenSkos2\Rdf\Uri) {
            $resource = $this->manager->fetchByUri($id);
        } else {
            $resource = $this->manager->fetchByUuid($id);
        }

        if (!$resource) {
            throw new NotFoundException('Resource not found by id: ' . $id, 404);
        }

        if ($resource->isDeleted()) {
            throw new Exception\DeletedException('Resource ' . $id . ' is deleted', 410);
        }

        return $resource;
    }

    /**
     * Gets the search options from params.
     * @param array $params
     * @param int $start
     * @param int $limit
     */
    protected function getSearchOptions($params, $start, $limit)
    {
        $options = [
            'start' => $start,
            'rows' => $limit,
        ];

        // tenant
        $tenant = null;
        if (isset($params['tenant'])) {
            $tenant = $this->getTenantFromParams($params);
            $options['tenants'] = [$tenant->code];
        }

        // collection -> set in OpenSKOS 2
        if (isset($params['collection'])) {
            $collection = $this->getCollection($params, $tenant);
            $options['collections'] = [$collection->getUri()];
        }

        // search query
        if (isset($params['q'])) {
            $options['searchText'] = $params['q'];
        }
        
        return $options;
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
        $fieldsMap = FieldsMaps::getOldToProperties();

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
     * Get request params, including parameters send in XML body
     *
     * @param ServerRequestInterface $request
     * @return array
     */
    protected function getParams(ServerRequestInterface $request)
    {
        $params = $request->getQueryParams();
        $doc = $this->getDomDocumentFromRequest($request);
        // is a tenant, collection or api key set in the XML?
        foreach (array('tenant', 'collection', 'key') as $attributeName) {
            $value = $doc->documentElement->getAttributeNS(OpenSkos::NAME_SPACE, $attributeName);
            if (!empty($value)) {
                $params[$attributeName] = $value;
            }
        }
        return $params;
    }
    
    /**
     * Get dom document from request
     *
     * @param ServerRequestInterface $request
     * @return \DOMDocument
     * @throws InvalidArgumentException
     */
    protected function getDomDocumentFromRequest(ServerRequestInterface $request)
    {
        $xml = $request->getBody();
        if (!$xml) {
            throw new InvalidArgumentException('No RDF-XML recieved', 400);
        }

        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml)) {
            throw new InvalidArgumentException('Recieved RDF-XML is not valid XML', 400);
        }

        //do some basic tests
        if ($doc->documentElement->nodeName != 'rdf:RDF') {
            throw new InvalidArgumentException(
                'Recieved RDF-XML is not valid: '
                . 'expected <rdf:RDF/> rootnode, got <'.$doc->documentElement->nodeName.'/>',
                400
            );
        }

        return $doc;
    }

    /**
     * @param $params
     * @param \OpenSKOS_Db_Table_Row_Tenant|null $tenant
     * @return OpenSKOS_Db_Table_Row_Collection
     * @throws InvalidArgumentException
     */
    protected function getCollection($params, $tenant)
    {
        if (empty($params['collection'])) {
            throw new InvalidArgumentException('No collection specified', 400);
        }
        $code = $params['collection'];
        $model = new \OpenSKOS_Db_Table_Collections();
        $collection = $model->findByCode($code, $tenant);
        if (null === $collection) {
            throw new InvalidArgumentException(
                'No such collection `'.$code.'` in this tenant.',
                404
            );
        }
        return $collection;
    }

    /**
     * Get error response
     *
     * @param integer $status
     * @param string $message
     * @return ResponseInterface
     */
    protected function getErrorResponse($status, $message)
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
     * @param int    $status
     * @return ResponseInterface
     */
    protected function getSuccessResponse($message, $status = 200)
    {
        $stream = new Stream('php://memory', 'wb+');
        $stream->write($message);
        $response = (new Response($stream, $status))
            ->withHeader('Content-Type', 'text/xml; charset="utf-8"');
        return $response;
    }

    /**
     * @param array $params
     * @return \OpenSKOS_Db_Table_Row_Tenant
     * @throws InvalidArgumentException
     */
    protected function getTenantFromParams($params)
    {
        if (empty($params['tenant'])) {
            throw new InvalidArgumentException('No tenant specified', 400);
        }

        return $this->getTenant($params['tenant']);
    }

    /**
     *
     * @param array $params
     * @return \OpenSKOS_Db_Table_Row_User
     * @throws InvalidArgumentException
     */
    protected function getUserFromParams($params)
    {
        if (empty($params['key'])) {
            throw new InvalidArgumentException('No key specified', 400);
        }
        return $this->getUserByKey($params['key']);
    }
}
