<?php

namespace OpenSkos2\Validator\Tenant;

use OpenSkos2\Tenant as Tenant;
use OpenSkos2\Validator\AbstractTenantValidator;

class vCardAdress extends AbstractTenantValidator
{
    
    protected function validateTenant(Tenant $resource)
    {
        return true;
    }
}