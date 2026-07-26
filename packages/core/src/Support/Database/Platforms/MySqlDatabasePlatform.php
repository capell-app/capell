<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\Platforms;

use Capell\Core\Contracts\Database\DatabasePlatform;
use Capell\Core\Contracts\Database\DatabaseProvisioner;
use Capell\Core\Contracts\Database\DatabaseQueryDialect;
use Capell\Core\Contracts\Database\DatabaseSchemaDialect;
use Capell\Core\Enums\Database\DatabaseFamily;
use Capell\Core\Support\Database\Provisioners\MySqlDatabaseProvisioner;
use Capell\Core\Support\Database\QueryDialects\MySqlQueryDialect;
use Capell\Core\Support\Database\SchemaDialects\MySqlSchemaDialect;

class MySqlDatabasePlatform implements DatabasePlatform
{
    private readonly DatabaseQueryDialect $queryDialect;

    private readonly DatabaseSchemaDialect $schemaDialect;

    private readonly DatabaseProvisioner $databaseProvisioner;

    public function __construct()
    {
        $this->queryDialect = new MySqlQueryDialect;
        $this->schemaDialect = new MySqlSchemaDialect($this->family());
        $this->databaseProvisioner = new MySqlDatabaseProvisioner;
    }

    public function drivers(): array
    {
        return ['mysql'];
    }

    public function family(): DatabaseFamily
    {
        return DatabaseFamily::MySql;
    }

    public function phpExtension(): string
    {
        return 'pdo_mysql';
    }

    public function queryDialect(): DatabaseQueryDialect
    {
        return $this->queryDialect;
    }

    public function schemaDialect(): DatabaseSchemaDialect
    {
        return $this->schemaDialect;
    }

    public function provisioner(): DatabaseProvisioner
    {
        return $this->databaseProvisioner;
    }
}
