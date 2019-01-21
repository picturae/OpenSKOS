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

namespace OpenSkos2\EasyRdf\Sparql;

use EasyRdf\Graph;
use EasyRdf\Exception;
use EasyRdf\Format;
use EasyRdf\Http;
use EasyRdf\RdfNamespace;
use EasyRdf\Utils;

class Client extends \EasyRdf\Sparql\Client
{
    /** The query/read address of the SPARQL Endpoint */
    private $queryUri = null;

    private $queryUri_has_params = false;

    /** The update/write address of the SPARQL Endpoint */
    private $updateUri = null;

    private $defaultGraph;

    /** Create a new SPARQL endpoint client
     *
     * If the query and update endpoints are the same, then you
     * only need to give a single URI.
     *
     * @param string $queryUri The address of the SPARQL Query Endpoint
     * @param string $updateUri Optional address of the SPARQL Update Endpoint
     * @param string $defaultGraph Required parameter vor virtuoso
     */
    public function __construct($queryUri, $updateUri = null, $defaultGraph)
    {
        $this->defaultGraph = $defaultGraph;
        $this->queryUri = $queryUri;

        if (strlen(parse_url($queryUri, PHP_URL_QUERY)) > 0) {
            $this->queryUri_has_params = true;
        } else {
            $this->queryUri_has_params = false;
        }

        if ($updateUri) {
            $this->updateUri = $updateUri;
        } else {
            $this->updateUri = $queryUri;
        }
        
        parent::__construct($queryUri, $updateUri);        
    }

    /**
     * Deletes the resource and inserts it with the new data.
     * @param string $uri The resource id
     * @param Graph|string $data The insert data
     */
    public function replace($uri, $data)
    {
        $query = 'DELETE WHERE {<' . $uri . '> ?predicate ?object};';
        $query .= PHP_EOL;
        $query .= 'INSERT DATA {' . $this->convertToTriples($data) . '}';
        
        $this->update($query);
    }

    /**
     * Override the default executeQuery method to set the defaultGraph parameter
     * 
     * Build http-client object, execute request and return a response
     *
     * @param string $processed_query
     * @param string $type            Should be either "query" or "update"
     *
     * @return Http\Response|\Zend\Http\Response
     * @throws Exception
     */
    protected function executeQuery($processed_query, $type)
    {        
        $client = Http::getDefaultHttpClient();
        $client->resetParameters();

        // Tell the server which response formats we can parse
        $sparql_results_types = array(
            'application/sparql-results+json' => 1.0,
            'application/sparql-results+xml' => 0.8
        );

        $client->setParameterGet('default-graph-uri', $this->defaultGraph);
        
        if ($type == 'update') {
            // accept anything, as "response body of a […] update request is implementation defined"
            // @see http://www.w3.org/TR/sparql11-protocol/#update-success
            $accept = Format::getHttpAcceptHeader($sparql_results_types);
            $client->setHeaders('Accept', $accept);

            $client->setMethod('POST');
            $client->setUri($this->updateUri);
            $client->setRawData($processed_query);
            $client->setHeaders('Content-Type', 'application/sparql-update');
        } elseif ($type == 'query') {
            $re = '(?:(?:\s*BASE\s*<.*?>\s*)|(?:\s*PREFIX\s+.+:\s*<.*?>\s*))*'.
                '(CONSTRUCT|SELECT|ASK|DESCRIBE)[\W]';

            $result = null;
            $matched = mb_eregi($re, $processed_query, $result);

            if (false === $matched or count($result) !== 2) {
                // non-standard query. is this something non-standard?
                $query_verb = null;
            } else {
                $query_verb = strtoupper($result[1]);
            }

            if ($query_verb === 'SELECT' or $query_verb === 'ASK') {
                // only "results"
                $accept = Format::formatAcceptHeader($sparql_results_types);
            } elseif ($query_verb === 'CONSTRUCT' or $query_verb === 'DESCRIBE') {
                // only "graph"
                $accept = Format::getHttpAcceptHeader();
            } else {
                // both
                $accept = Format::getHttpAcceptHeader($sparql_results_types);
            }

            $client->setHeaders('Accept', $accept);

            $encodedQuery = 'query=' . urlencode($processed_query);

            // Use GET if the query is less than 2kB
            // 2046 = 2kB minus 1 for '?' and 1 for NULL-terminated string on server
            if (strlen($encodedQuery) + strlen($this->queryUri) <= 2046) {
                $delimiter = $this->queryUri_has_params ? '&' : '?';

                $client->setMethod('GET');
                $client->setUri($this->queryUri . $delimiter . $encodedQuery);
            } else {
                // Fall back to POST instead (which is un-cacheable)
                $client->setMethod('POST');
                $client->setUri($this->queryUri);
                $client->setRawData($encodedQuery);
                $client->setHeaders('Content-Type', 'application/x-www-form-urlencoded');
            }
        } else {
            throw new Exception('unexpected request-type: '.$type);
        }

        return $client->request();
    }    
}
