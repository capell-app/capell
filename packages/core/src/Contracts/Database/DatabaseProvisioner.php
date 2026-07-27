<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Database;

use Capell\Core\Enums\Database\DatabaseProvisioningResult;

interface DatabaseProvisioner
{
    /**
     * @param  array<string, mixed>  $configuration
     */
    public function provision(string $connectionName, array $configuration): DatabaseProvisioningResult;
}
