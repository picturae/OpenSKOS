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
use OpenSkos2\Namespaces;
use OpenSkos2\Namespaces\OpenSkos;
use OpenSkos2\Namespaces\Rdf;
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
use OpenSkos2\ConceptManager;
use OpenSkos2\FieldsMaps;
use OpenSkos2\Validator\Resource as ResourceValidator;
use OpenSkos2\Tenant as Tenant;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Diactoros\Response;
use Zend\Diactoros\Stream;

/**
 * Map an API request from the old application to still work with the new backend on Jena
 */
class Concept extends Resource
{
    /**
     * Concept manager
     *
     * @var \OpenSkos2\ConceptManager
     */
    private $conceptManager;

    /**
     *
     * @param \OpenSkos2\Rdf\ResourceManager $manager
     * @param \OpenSkos2\ConceptManager $conceptManager
     * @param \OpenSkos2\Search\Autocomplete $searchAutocomplete
     */
    public function __construct(
        ResourceManager $manager,
        \OpenSkos2\Search\Autocomplete $searchAutocomplete,
        ConceptManager $conceptManager
    ) {
        parent::__construct($manager, $searchAutocomplete);
        $this->conceptManager = $conceptManager;
    }

    /**
     * Create the concept
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
     * Update a concept
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function update(ServerRequestInterface $request)
    {
        try {
            $concept = $this->getConceptFromRequest($request);
            $existingConcept = $this->manager->fetchByUri((string)$concept->getUri());

            $params = $this->getParams($request);

            $tenant = $this->getTenantFromParams($params);

            $collection = $this->getCollection($params, $tenant);
            $user = $this->getUserFromParams($params);

            $this->resourceEditAllowed($concept, $tenant, $user);

            $concept->ensureMetadata(
                $tenant->code,
                $collection->getUri(),
                $user->getFoafPerson(),
                $existingConcept->getStatus()
            );

            $this->validate($concept, $tenant);

            $this->conceptManager->replaceAndCleanRelations($concept);
        } catch (ApiException $ex) {
            return $this->getErrorResponse($ex->getCode(), $ex->getMessage());
        } catch (\OpenSkos2\Exception\ResourceNotFoundException $ex) {
            return $this->getErrorResponse(404, 'Concept not found try insert');
        }

        $xml = (new \OpenSkos2\Api\Transform\DataRdf($concept))->transform();
        return $this->getSuccessResponse($xml);
    }

    /**
     * Perform a soft delete on a concept
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
            /* @var $concept \OpenSkos2\Concept */
            $concept = $this->manager->fetchByUri($id);
            if (!$concept) {
                throw new NotFoundException('Concept not found by id :' . $id, 404);
            }

            if ($concept->isDeleted()) {
                throw new NotFoundException('Concept already deleted :' . $id, 410);
            }

            $user = $this->getUserFromParams($params);
            $tenant = $this->getTenantFromParams($params);

            $this->resourceEditAllowed($concept, $tenant, $user);

            $this->manager->deleteSoft($concept);
        } catch (ApiException $ex) {
            return $this->getErrorResponse($ex->getCode(), $ex->getMessage());
        }

        $xml = (new \OpenSkos2\Api\Transform\DataRdf($concept))->transform();
        return $this->getSuccessResponse($xml, 202);
    }
    
    /**
     * Gets the search options from params.
     * @param array $params
     * @param int $start
     * @param int $limit
     */
    protected function getSearchOptions($params, $start, $limit)
    {
        $options = parent::getSearchOptions($params, $start, $limit);
        $options['status'] = [\OpenSkos2\Concept::STATUS_CANDIDATE, \OpenSkos2\Concept::STATUS_APPROVED];
        
        // conceptScheme
        if (isset($params['scheme'])) {
            $options['conceptScheme'] = [$params['scheme']];
        }
        
        return $options;
    }

    /**
     * Applies all validators to the concept.
     * @param \OpenSkos2\Concept $concept
     * @param \OpenSKOS_Db_Table_Row_Tenant $tenant
     * @throws InvalidArgumentException
     */
    protected function validate(\OpenSkos2\Concept $concept, \OpenSKOS_Db_Table_Row_Tenant $tenant)
    {
        $validator = new ResourceValidator(
            $this->manager,
            new Tenant($tenant->code)
        );


        if (!$validator->validate($concept)) {
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
    protected function handleCreate(ServerRequestInterface $request)
    {
        $concept = $this->getConceptFromRequest($request);

        if (!$concept->isBlankNode() && $this->manager->askForUri((string)$concept->getUri())) {
            throw new InvalidArgumentException(
                'The concept with uri ' . $concept->getUri() . ' already exists. Use PUT instead.',
                400
            );
        }

        $params = $this->getParams($request);

        $tenant = $this->getTenantFromParams($params);
        $collection = $this->getCollection($params, $tenant, $concept);
        $user = $this->getUserFromParams($params);

        $concept->ensureMetadata(
            $tenant->code,
            $collection->getUri(),
            $user->getFoafPerson()
        );

        $autoGenerateUri = $this->checkConceptIdentifiers($request, $concept);
        if ($autoGenerateUri) {
            $concept->selfGenerateUri(
                new Tenant($tenant->code),
                $this->conceptManager
            );
        }

        $this->validate($concept, $tenant);

        $this->manager->insert($concept);

        $rdf = (new Transform\DataRdf($concept))->transform();
        return $this->getSuccessResponse($rdf, 201);
    }

    /**
     * Get the skos concept from the request to insert or update
     * does some validation to see if the xml is valid
     *
     * @param ServerRequestInterface $request
     * @return \OpenSkos2\Concept
     * @throws InvalidArgumentException
     */
    protected function getConceptFromRequest(ServerRequestInterface $request)
    {
        $doc = $this->getDomDocumentFromRequest($request);

        $descriptions = $doc->documentElement->getElementsByTagNameNS(Rdf::NAME_SPACE, 'Description');
        if ($descriptions->length != 1) {
            throw new InvalidArgumentException(
                'Expected exactly one '
                . '/rdf:RDF/rdf:Description, got '.$descriptions->length,
                412
            );
        }

        // remove the api key
        $doc->documentElement->removeAttributeNS(OpenSkos::NAME_SPACE, 'key');

        $resource = (new Text($doc->saveXML()))->getResources();

        if (!isset($resource[0]) || !$resource[0] instanceof \OpenSkos2\Concept) {
            throw new InvalidArgumentException('XML Could not be converted to SKOS Concept', 400);
        }

        /** @var $concept \OpenSkos2\Concept **/
        $concept = $resource[0];

        // Is a tenant in the custom openskos xml attributes but not in the rdf add the values to the concept
        $xmlTenant = $doc->documentElement->getAttributeNS(OpenSkos::NAME_SPACE, 'tenant');
        if (!$concept->getTenant() && !empty($xmlTenant)) {
            $concept->addUniqueProperty(OpenSkos::TENANT, new \OpenSkos2\Rdf\Literal($xmlTenant));
        }

        // If there still is no tenant add it from the query params
        $params = $request->getQueryParams();
        if (!$concept->getTenant() && isset($params['tenant'])) {
            $concept->addUniqueProperty(OpenSkos::TENANT, new \OpenSkos2\Rdf\Literal($params['tenant']));
        }

        if (!$concept->getTenant()) {
            throw new InvalidArgumentException('No tenant specified', 400);
        }

        return $concept;
    }

    /**
     * Check if we need to generate or not concept identifiers (uri).
     * Validates any existing identifiers.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @param \OpenSkos2\Concept $concept
     * @return bool If an uri must be autogenerated
     * @throws InvalidArgumentException
     */
    protected function checkConceptIdentifiers(ServerRequestInterface $request, \OpenSkos2\Concept $concept)
    {
        $params = $request->getQueryParams();

        // We return if an uri must be autogenerated
        $autoGenerateIdentifiers = false;
        if (!empty($params['autoGenerateIdentifiers'])) {
            $autoGenerateIdentifiers = filter_var(
                $params['autoGenerateIdentifiers'],
                FILTER_VALIDATE_BOOLEAN
            );
        }

        if ($autoGenerateIdentifiers) {
            if (!$concept->isBlankNode()) {
                throw new InvalidArgumentException(
                    'Parameter autoGenerateIdentifiers is set to true, but the '
                    . 'xml already contains uri (rdf:about).',
                    400
                );
            }
        } else {
            // Is uri missing
            if ($concept->isBlankNode()) {
                throw new InvalidArgumentException(
                    'Uri (rdf:about) is missing from the xml. You may consider using autoGenerateIdentifiers.',
                    400
                );
            }
        }

        return $autoGenerateIdentifiers;
    }
}
