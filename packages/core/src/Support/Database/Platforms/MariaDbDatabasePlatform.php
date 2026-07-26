<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\Platforms;

use Capell\Core\Enums\Database\DatabaseFamily;
use Override;

final class MariaDbDatabasePlatform extends MySqlDatabasePlatform
{
    #[Override]
    public function drivers(): array
    {
        return ['mariadb'];
    }

    #[Override]
    public function family(): DatabaseFamily
    {
        return DatabaseFamily::MariaDb;
    }
}
