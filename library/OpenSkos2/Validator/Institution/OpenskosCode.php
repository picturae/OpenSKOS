<?php

namespace OpenSkos2\Validator\Institution;

use OpenSkos2\Validator\AbstractInstitutionValidator;
use OpenSkos2\Institution as Tenant;

class OpenskosCode extends AbstractInstitutionValidator
{
    protected function validateTenant(Institution $resource)
    {
        return $this->validateOpenskosCode($resource);
    }
}
