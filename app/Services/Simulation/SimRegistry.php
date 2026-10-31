<?php

namespace App\Services\Simulation;

use App\Contracts\DomainRegistrar;
use App\Services\Domains\DomainRegistrarRegistry;

/** Every registrar is the simulated one (`mycs:simulate`); «manual» stays what it is: it calls nobody. */
final class SimRegistry extends DomainRegistrarRegistry
{
    public function for(string $key): DomainRegistrar
    {
        $key = trim($key);

        return $key === '' || $key === 'manual' ? parent::for($key) : new SimRegistrar;
    }
}
