<?php

namespace OpenSkos2\Validator\Institution;

use OpenSkos2\Institution;
use OpenSkos2\Namespaces\VCard;
use OpenSkos2\Validator\AbstractInstitutionValidator;

class VCardEmail extends AbstractInstitutionValidator
{

    protected function validateTenant(Institution $resource)
    {
        return $this->validateProperty($resource, VCard::EMAIL, true, true, false, true);
    }
}
