<?php

namespace OpenSkos2\Validator\Institution;

use OpenSkos2\Institution as Tenant;
use OpenSkos2\Validator\AbstractInstitutionValidator;

class VCardAdress extends AbstractInstitutionValidator
{
    protected function validateTenant(Institution $resource)
    {
        return true;
    }
}
