<?php

namespace OpenSkos2\Validator\Institution;

use OpenSkos2\Institution as Tenant;
use OpenSkos2\Namespaces\OpenSkos;
use OpenSkos2\Validator\AbstractInstitutionValidator;

class OpenskosEnableStatussesSystem extends AbstractInstitutionValidator
{
    protected function validateTenant(Institution $resource)
    {
        return $this->validateProperty($resource, OpenSkos::ENABLESTATUSSESSYSTEMS, true, true, true, false);
    }
}
