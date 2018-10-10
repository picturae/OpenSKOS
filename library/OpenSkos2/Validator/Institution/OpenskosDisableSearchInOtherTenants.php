<?php

namespace OpenSkos2\Validator\Institution;

use OpenSkos2\Institution as Tenant;
use OpenSkos2\Namespaces\OpenSkos;
use OpenSkos2\Validator\AbstractInstitutionValidator;

class OpenskosDisableSearchInOtherTenants extends AbstractInstitutionValidator
{
    protected function validateTenant(Institution $resource)
    {
        return $this->validateProperty($resource, OpenSkos::DISABLESEARCHINOTERTENANTS, true, true, true, false);
    }
}
