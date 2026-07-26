<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\Provisioners;

use Capell\Core\Contracts\Database\DatabaseProvisioner;
use RuntimeException;

final class PostgresDatabaseProvisioner extends AbstractServerDatabaseProvisioner implements DatabaseProvisioner
{
    public function provision(string $connectionName, array $configuration): bool
    {
        $database = trim((string) ($configuration['database'] ?? ''));

        if ($database === '') {
            return false;
        }

        $pdo = $this->pdo(sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $this->firstHost($configuration['host'] ?? null),
            (string) ($configuration['port'] ?? '5432'),
            (string) ($configuration['maintenance_database'] ?? 'postgres'),
        ), $configuration);
        $statement = $pdo->prepare('select 1 from pg_database where datname = ?');
        $statement->execute([$database]);
        $created = $statement->fetchColumn() === false;

        if ($created) {
            throw_if(str_contains($database, "\0"), RuntimeException::class, 'Database name cannot contain null bytes.');
            $pdo->exec('CREATE DATABASE "' . str_replace('"', '""', $database) . '"');
        }

        $this->refresh($connectionName);

        return $created;
    }
}
