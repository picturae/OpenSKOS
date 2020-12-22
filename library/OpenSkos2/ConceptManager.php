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

namespace OpenSkos2;

use Asparagus\QueryBuilder;
use OpenSkos2\Namespaces\OpenSkos;
use OpenSkos2\Namespaces\Skos;
use OpenSkos2\Namespaces\Xsd;
use OpenSkos2\Namespaces\DcTerms;
use OpenSkos2\Namespaces\Rdf;
use OpenSkos2\Rdf\Literal;
use OpenSkos2\Rdf\Uri;
use OpenSkos2\Rdf\ResourceManagerWithSearch;
use OpenSkos2\Rdf\Resource;
use OpenSkos2\Rdf\Serializer\NTriple;
use OpenSkos2\SkosXl\LabelManager;

class ConceptManager extends ResourceManagerWithSearch
{

    /**
     * What is the basic resource for this manager.
     * @var string NULL means any resource.
     */
    protected $resourceType = Concept::TYPE;

    /**
     * @var LabelManager
     */
    protected $labelManager;

    /**
     * @return LabelManager
     */
    public function getLabelManager()
    {
        return $this->labelManager;
    }

     /**
     * @return LabelManager
     */
    public function getSolrManager()
    {
        return $this->solrResourceManager;
    }

    // uses and overrides the parent's method
    public function findResourceById($id, $resourceType)
    {
        $concept = parent::findResourceById($id, $resourceType);
        if ($concept->isDeleted()) {
            throw new \Exception('Resource with id ' . $id . ' is deleted');
        }
        return $concept;
    }

    /**
     * @param LabelManager $labelManager
     */
    public function setLabelManager(LabelManager $labelManager)
    {
        $this->labelManager = $labelManager;
    }

    /**
     * @param \OpenSkos2\Rdf\Resource $resource
     * @throws ResourceAlreadyExistsException
     */
    public function insert(Resource $resource)
    {
        parent::insert($resource);

        $labelHelper = new Concept\LabelHelper($this->labelManager);
        $labelHelper->insertLabels($resource);
    }

    /**
     * Deletes and then inserts the resourse.
     * @param \OpenSkos2\Rdf\Resource $resource
     */
    public function replace(Resource $resource)
    {
        parent::replace($resource);

        $labelHelper = new Concept\LabelHelper($this->labelManager);
        $labelHelper->insertLabels($resource);
    }

    /**
     * Deletes and then inserts the concept.
     * Also deletes all relations for which the concept is object.
     * @param Concept $concept
     */
    public function replaceAndCleanRelations(Concept $concept)
    {

        $this->deleteRelationsWhereObject($concept);
        $this->replace($concept);
    }

    /**
     * Perform basic autocomplete search on pref and alt labels
     *
     * @param string $term
     * @param string $searchLabel
     * @param string $returnLabel
     * @param string $lang
     * @return array
     */
    public function autoComplete($term, $searchLabel = Skos::PREFLABEL, $returnLabel = Skos::PREFLABEL, $lang = null)
    {
        $literalKey = new Literal('^' . $term);
        $eTerm = (new NTriple())->serialize($literalKey);

        $q = new QueryBuilder();

        // Do a distinct query on pref and alt labels where string starts with $term
        $query = $q->selectDistinct('?returnLabel')
            ->where('?subject', '<' . OpenSkos::STATUS . '>', '"' . Concept::STATUS_APPROVED . '"')
            ->also('<' . $returnLabel . '>', '?returnLabel')
            ->also('<' . $searchLabel . '>', '?searchLabel')
            ->limit(50);

        $filter = 'regex(str(?searchLabel), ' . $eTerm . ', "i")';
        if (!empty($lang)) {
            $filter .= ' && ';
            $filter .= 'lang(?returnLabel) = "' . $lang . '"';
        }
        $query->filter($filter);

        $result = $this->query($query);
        $items = [];
        $i = 0;
        foreach ($result as $literal) {
            $items[$i] = $literal->returnLabel->getValue();
            $i++;
        }
        return $items;
    }

    /**
     * Add relations to a skos concept
     *
     * @param string $uri
     * @param string $relationType
     * @param array|string $uris
     * @throws Exception\InvalidArgumentException
     */
    public function addRelation($uri, $relationType, $uris)
    {
        if (!in_array($relationType, Skos::getSkosRelations(), true)) {
            throw new Exception\InvalidArgumentException('Relation type not supported: ' . $relationType);
        }

        // @TODO Add check everywhere we may need it.
        if (in_array($relationType, [Skos::BROADERTRANSITIVE, Skos::NARROWERTRANSITIVE])) {
            throw new Exception\InvalidArgumentException(
                'Relation type "' . $relationType . '" will be inferred. Not supported explicitly.'
            );
        }

        $graph = new \EasyRdf\Graph();

        if (!is_array($uris)) {
            $uris = [$uris];
        }
        foreach ($uris as $related) {
            $graph->addResource($uri, $relationType, $related);
        }

        $this->client->insert($graph);
    }

    /**
     * Delete relations between two skos concepts.
     * Deletes in both directions (narrower and broader for example).
     * @param string $subjectUri
     * @param string $relationType
     * @param string $objectUri
     * @throws Exception\InvalidArgumentException
     */
    public function deleteRelation($subjectUri, $relationType, $objectUri)
    {
        if (!in_array($relationType, Skos::getSkosRelations(), true)) {
            throw new Exception\InvalidArgumentException('Relation type not supported: ' . $relationType);
        }

        $this->deleteMatchingTriples(
            new Uri($subjectUri),
            $relationType,
            new Uri($objectUri)
        );

        $this->deleteMatchingTriples(
            new Uri($objectUri),
            Skos::getInferredRelationsMap()[$relationType],
            new Uri($subjectUri)
        );
    }

    /**
     * Get all concepts that are related as subjects to the given label uri
     * @param Label $label
     * @return ConceptCollection
     */
    public function fetchByLabel($label)
    {
        $query = '
                DESCRIBE ?subject
                WHERE {
                    ?subject ?predicate <' . $label->getUri() . '> .
                    ?subject <' . \OpenSkos2\Namespaces\Rdf::TYPE . '> <' . \OpenSkos2\Concept::TYPE . '>
                }';

        $concepts = $this->fetchQuery($query);

        return $concepts;
    }

    /**
     * Fetches all relations (can be a large number) for the given relation type.
     * @param string $uri
     * @param string $relationType Skos::BROADER for example.
     * @param string $conceptScheme , optional Specify if you want relations from single concept scheme only.
     * @return ConceptCollection
     */
    public function fetchRelations($uri, $relationType, $conceptScheme = null)
    {
        // @TODO It is possible that there are relations to uris, for which there is no resource.

        $allRelations = new ConceptCollection([]);

        if (!$uri instanceof Uri) {
            $uri = new Uri($uri);
        }

        $patterns = [
            [$uri, $relationType, '?subject'],
        ];

        if (!empty($conceptScheme)) {
            $patterns[Skos::INSCHEME] = new Uri($conceptScheme);
        }

        $start = 0;
        $step = 100;
        do {
            $relations = $this->fetchOnSubject($patterns, $start, $step);
            foreach ($relations as $relation) {
                $allRelations->append($relation);
            }
            $start += $step;
        } while (!(count($relations) < $step));

        return $allRelations;
    }

    /**
     * Delete all relations for which the concepts is object (target)
     * @param Concept $concept
     */
    public function deleteRelationsWhereObject(Concept $concept)
    {
    	$query_relations = <<<QUERY_RELATIONS
SELECT DISTINCT ?predicate
WHERE{
?subject ?predicate ?object
}
VALUES (?object) {(<%s>)}
QUERY_RELATIONS;

    	$query_relations = sprintf($query_relations, $concept->getUri());

		$response = $this->query($query_relations);
		foreach ($response as $relation){
			$relation_type = $relation->predicate->getUri();
			if(in_array($relation_type, Skos::getSkosRelations())) {
				$this->deleteMatchingTriples('?subject', $relation_type, $concept);
			}
		}
    }

    /**
     * Checks if there is a concept with the same pref label.
     * @param string $prefLabel
     * @return bool
     */
    public function askForPrefLabel($prefLabel)
    {
        $solrManager = $this->solrResourceManager;

        $res = $solrManager->doesMatchingPrefLabelExist($prefLabel);

        return $res;

        /*
         * We've switched back to using Solr for checking prefLabels, for performance reasons
         * The old Jena check was this code, should you ever need to restore it
         *
         */
        /*
        return $this->askForMatch([
                [
                    'predicate' => Skos::PREFLABEL,
                    'value' => new Literal($prefLabel),
                    'ignoreLanguage' => true
                ]
        ]);
        */
    }

    /**
     * Deletes all concepts inside a concept scheme.
     * @param \OpenSkos2\ConceptScheme $scheme
     * @param \OpenSkos2\Person $deletedBy
     */
    public function deleteSoftInScheme(ConceptScheme $scheme, Person $deletedBy)
    {
        $start = 0;
        $step = 100;
        do {
            $concepts = $this->fetch(
                [
                Skos::INSCHEME => $scheme,
                ],
                $start,
                $step
            );

            foreach ($concepts as $concept) {
                $inSchemes = $concept->getProperty(Skos::INSCHEME);
                if (count($inSchemes) == 1) {
                    $this->deleteSoft($concept, $deletedBy);
                } else {
                    $newSchemes = [];
                    foreach ($inSchemes as $inScheme) {
                        if (strcasecmp($inScheme->getUri(), $scheme->getUri()) !== 0) {
                            $newSchemes[] = $inScheme;
                        }
                    }
                    $concept->setProperties(Skos::INSCHEME, $newSchemes);
                    $this->replace($concept);
                }
            }
            $start += $step;
        } while (!(count($concepts) < $step));
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


    public function search(
        $query,
        $rows = 20,
        $start = 0,
        &$numFound = 0,
        $sorts = null
    ) {

        $uriList = $this->solrResourceManager->search($query, $rows, $start, $numFound, $sorts);
        $resultCollection = $this->fetchByUris($uriList);

        return $resultCollection;
    }

    public function searchInSolr(
        $query,
        $rows = 20,
        $start = 0,
        &$numFound = 0,
        $sorts = null
    ) {

        $uriList = $this->solrResourceManager->search($query, $rows, $start, $numFound, $sorts, null, true);

        return $uriList;
    }

    /**
     * Gets the current max numeric notation for all concepts. Fast.
     * @param \OpenSkos2\Tenant $tenant
     * @return int|null
     */
    public function fetchMaxNumericNotationFromIndex(Tenant $tenant)
    {
        //Oh Help! Olha changed this to use $tenant->getUri.
        // I have no idea why, but I'm sure she had a reason. However, now lots of stuff is broken

        // Gets the maximum of all max_numeric_notation fields
        $max = $this->solrResourceManager->getMaxFieldValue(
            'tenant:"' . $tenant->getCode() . '"',
            'max_numeric_notation'
        );
        return intval($max);
    }

    /**
     * Gets the current max numeric notation.
     * This method is extremely slow...
     * @see self::fetchMaxNumericNotationFromIndex
     * @param \OpenSkos2\Tenant $tenant
     * @return int|null
     */
    public function fetchMaxNumericNotation(Tenant $tenant)
    {
        // This method is slow - use fetchMaxNumericNotationFromIndex where possible.
        $maxNotationQuery = (new QueryBuilder())
            ->select('(MAX(<' . Xsd::NONNEGATIVEINTEGER . '>(?notation)) AS ?maxNotation)')
            ->where('?subject', '<' . Skos::NOTATION . '>', '?notation')
            ->also('<' . OpenSkos::TENANT . '>', $this->valueToTurtle(new Literal($tenant->getCode())))
            ->filter('regex(?notation, \'^[0-9]*$\', "i")');

        $maxNotationResult = $this->query($maxNotationQuery);

        $maxNotation = null;
        if (!empty($maxNotationResult->offsetGet(0)->maxNotation)) {
            $maxNotation = $maxNotationResult->offsetGet(0)->maxNotation->getValue();
        }

        return $maxNotation;
    }

    /**
     * Gets the current min dcterms:modified date.
     * @return \DateTime
     */
    public function fetchMinModifiedDate()
    {
        $now = new \DateTime();

        $minDateQuery = (new QueryBuilder())
            ->select('(MIN(?date) AS ?minDate)')
            ->where('?subject', '<' . DcTerms::MODIFIED . '>', '?date')
            ->also('<' . Rdf::TYPE . '>', '<' . $this->resourceType . '>');



        $result = $this->solrResourceManager->search(
            'status:*',
            1,
            0,
            $numFound,
            ['sort_d_modified_earliest' => 'asc']
        );

        $uri = current($result);

        if (!$uri) {
            return $now;
        }

        $concept = $this->fetchByUri($uri);
        if (!$concept) {
            return $now;
        }

        $date = current($concept->getProperty(DcTerms::MODIFIED));
        if ($date->getValue() instanceof \DateTime) {
            return $date->getValue();
        }

        return $now;
    }

    /**
     * Delete relations between two skos concepts.
     * Deletes in both directions (narrower and broader for example).
     * @param string $subjectUri
     * @param string $relationType
     * @param string $objectUri
     * @throws Exception\InvalidArgumentException
     */
    public function deleteRelationTriple($subjectUri, $relationType, $objectUri)
    {

        $this->deleteMatchingTriples(
            new Uri($subjectUri),
            $relationType,
            new Uri($objectUri)
        );
        $inverses = array_merge(Skos::getInverseRelationsMap(), $this->getCustomInverses());
        $this->deleteMatchingTriples(
            new Uri($objectUri),
            $inverses[$relationType],
            new Uri($subjectUri)
        );
    }

    /**
     * Add relations to a skos concept
     *
     * @param string $uri
     * @param string $relationType
     * @param array|string $uris
     * @throws Exception\InvalidArgumentException
     */
    public function addRelationTriple($uri, $relationType, $uris)
    {
        // @TODO Add check everywhere we may need it.
        if (in_array($relationType, [Skos::BROADERTRANSITIVE, Skos::NARROWERTRANSITIVE])) {
            throw new Exception\InvalidArgumentException(
                'Relation type "' . $relationType . '" will be inferred. Not supported explicitly.'
            );
        }

        $graph = new \EasyRdf\Graph();

        if (!is_array($uris)) {
            $uris = [$uris];
        }
        foreach ($uris as $related) {
            $graph->addResource($uri, $relationType, $related);
        }

        $this->client->insert($graph);
    }


    public function fetchNameUri()
    {
        $query = "SELECT ?uri ?name WHERE { ?uri  <" . Skos::PREFLABEL . "> ?name ."
            . " ?uri  <" . RdfNameSpace::TYPE . "> <".\OpenSkos2\Concept::TYPE.">. }";
        $response = $this->query($query);
        $result = $this->makeNameUriMap($response);
        return $result;
    }


    public function fetchNameSearchID()
    {
        $query = "SELECT ?name ?searchid WHERE { ?uri  <" . Skos::PREFLABEL . "> ?name . "
        . "?uri  <" . OpenSkosNameSpace::UUID . "> ?searchid ."
        . " ?uri  <" . RdfNameSpace::TYPE . "> <".\OpenSkos2\Concept::TYPE.">. }";
        $response = $this->query($query);
        $result = $this->makeNameSearchIDMap($response);
        return $result;
    }
}
