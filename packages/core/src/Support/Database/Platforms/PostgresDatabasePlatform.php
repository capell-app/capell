<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\Platforms;

use Capell\Core\Contracts\Database\DatabasePlatform;
use Capell\Core\Contracts\Database\DatabaseProvisioner;
use Capell\Core\Contracts\Database\DatabaseQueryDialect;
use Capell\Core\Contracts\Database\DatabaseSchemaDialect;
use Capell\Core\Enums\Database\DatabaseFamily;
use Capell\Core\Support\Database\Provisioners\PostgresDatabaseProvisioner;
use Capell\Core\Support\Database\QueryDialects\PostgresQueryDialect;
use Capell\Core\Support\Database\SchemaDialects\PostgresSchemaDialect;

final readonly class PostgresDatabasePlatform implements DatabasePlatform
{
    public function drivers(): array
    {
        return ['pgsql', 'postgres', 'postgresql'];
    }

    public function family(): DatabaseFamily
    {
        return DatabaseFamily::PostgreSql;
    }

    public function phpExtension(): string
    {
        return 'pdo_pgsql';
    }

    public function queryDialect(): DatabaseQueryDialect
    {
        return new PostgresQueryDialect;
    }

    public function schemaDialect(): DatabaseSchemaDialect
    {
        return new PostgresSchemaDialect;
    }

    public function provisioner(): ?DatabaseProvisioner
    {
        return new PostgresDatabaseProvisioner;
    }
}
