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

namespace OpenSkos2\Validator\Concept;

use OpenSkos2\Concept;
use OpenSkos2\Namespaces\Skos;
use OpenSkos2\Validator\AbstractConceptValidator;

class DuplicateBroader extends AbstractConceptValidator
{

    /**
     * @param Concept $concept
     * @return bool
     */
    protected function validateConcept(Concept $concept)
    {
        $broaderTerms = $concept->getProperty(Skos::BROADER);

        if (empty($broaderTerms)) {
            return true;
        }

        $loopedConcepts = [];
        foreach ($broaderTerms as $broaderTerm) {
            if (isset($loopedConcepts[$broaderTerm->getUri()])) {
                $this->errorMessages[] = "Broader term {$broaderTerm->getUri()} is defined more than once";
                return false;
            }
            $loopedConcepts[$broaderTerm->getUri()] = true;
        }

        return true;
    }
}
