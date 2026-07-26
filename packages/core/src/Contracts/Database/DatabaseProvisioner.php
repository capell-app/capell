<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Database;

interface DatabaseProvisioner
{
    /**
     * @param  array<string, mixed>  $configuration
     */
    public function provision(string $connectionName, array $configuration): bool;
}
