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

/**
 * Map an API request from the old application to still work with the new backend on Jena
 */
class ConceptScheme extends Resource
{
    /**
     * Gets the search options from params.
     * @param array $params
     * @param int $start
     * @param int $limit
     */
    protected function getSearchOptions($params, $start, $limit)
    {
        $options = parent::getSearchOptions($params, $start, $limit);
        
        // @TODO Make it to come from the manager where it should anyway.
        $options['rdfType'] = [\OpenSkos2\ConceptScheme::TYPE];
        
        return $options;
    }
}
