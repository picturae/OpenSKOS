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

namespace OpenSkos2\Api\Response\ResultSet;

use OpenSkos2\Api\Response\ResultSetResponse;
use OpenSkos2\Api\Response\BackwardCompatibility;

/**
 * Provide the json output for find-* api
 */
class JsonResponse extends ResultSetResponse
{

    /**
     * Get response
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getResponse()
    {
        return new \Zend\Diactoros\Response\JsonResponse($this->getResponseData());
    }

    /**
     * Gets the response data.
     * @return array
     */
    protected function getResponseData()
    {
        return [
            'response' => [
                'numFound' => $this->result->getTotal(),
                'rows' => $this->result->getLimit(),
                'start' => $this->result->getStart(),
                'docs' => $this->getDocs()
            ]
        ];
    }

    /**
     * Get docs property response
     *
     * @return array
     */
    protected function getDocs()
    {
        $docs = [];
        foreach ($this->result->getResources() as $resource) {
        	$da_obj = new \OpenSkos2\Api\Transform\DataArray(
				$resource,
				$this->propertiesList,
				$this->excludePropertiesList
			);
            $nResource = $da_obj->transform();
            // default backward compatible
            if (!isset($this->customInit) || count($this->customInit) === 0) { //short circuit
                $backwardCompatible = true;
            } else {
                $backwardCompatible= $this->customInit['backward_compatible'];
            }
            if ($backwardCompatible) {
                $nResource2 = (new BackwardCompatibility())->backwardCompatibilityMap(
                    $nResource,
                    $resource->getType()->getUri()
                );
            } else {
                $nResource2 = $nResource;
            }
            $docs[] = $nResource2;
        }
        return $docs;
    }
}
