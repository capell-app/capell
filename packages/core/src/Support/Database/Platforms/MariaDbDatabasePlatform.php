<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\Platforms;

use Capell\Core\Enums\Database\DatabaseFamily;

final class MariaDbDatabasePlatform extends MySqlDatabasePlatform
{
    public function drivers(): array
    {
        return ['mariadb'];
    }

    public function family(): DatabaseFamily
    {
        return DatabaseFamily::MariaDb;
    }
}
