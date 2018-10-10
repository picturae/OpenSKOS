<?php

namespace OpenSkos2\Validator\ConceptScheme;

use OpenSkos2\ConceptScheme;
use OpenSkos2\Validator\AbstractConceptSchemeValidator;

class OpenSkosInstitution extends AbstractConceptSchemeValidator
{

    protected function validateSchema(ConceptScheme $resource)
    {
         return $this->checkTenant($resource);
    }
}
