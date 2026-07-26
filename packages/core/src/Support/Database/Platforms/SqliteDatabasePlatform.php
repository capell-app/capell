<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\Platforms;

use Capell\Core\Contracts\Database\DatabasePlatform;
use Capell\Core\Contracts\Database\DatabaseProvisioner;
use Capell\Core\Contracts\Database\DatabaseQueryDialect;
use Capell\Core\Contracts\Database\DatabaseSchemaDialect;
use Capell\Core\Enums\Database\DatabaseFamily;
use Capell\Core\Support\Database\Provisioners\SqliteDatabaseProvisioner;
use Capell\Core\Support\Database\QueryDialects\SqliteQueryDialect;
use Capell\Core\Support\Database\SchemaDialects\SqliteSchemaDialect;

final readonly class SqliteDatabasePlatform implements DatabasePlatform
{
    public function drivers(): array
    {
        return ['sqlite'];
    }

    public function family(): DatabaseFamily
    {
        return DatabaseFamily::Sqlite;
    }

    public function phpExtension(): string
    {
        return 'pdo_sqlite';
    }

    public function queryDialect(): DatabaseQueryDialect
    {
        return new SqliteQueryDialect;
    }

    public function schemaDialect(): DatabaseSchemaDialect
    {
        return new SqliteSchemaDialect;
    }

    public function provisioner(): ?DatabaseProvisioner
    {
        return new SqliteDatabaseProvisioner;
    }
}
