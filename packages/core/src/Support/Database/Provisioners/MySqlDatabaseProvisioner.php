<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\Provisioners;

use Capell\Core\Contracts\Database\DatabaseProvisioner;
use Capell\Core\Enums\Database\DatabaseProvisioningResult;

final class MySqlDatabaseProvisioner extends AbstractServerDatabaseProvisioner implements DatabaseProvisioner
{
    public function provision(string $connectionName, array $configuration): DatabaseProvisioningResult
    {
        $database = trim((string) ($configuration['database'] ?? ''));

        if ($database === '') {
            return DatabaseProvisioningResult::Unavailable;
        }

        $socket = trim((string) ($configuration['unix_socket'] ?? ''));
        $dsn = $socket !== ''
            ? 'mysql:unix_socket=' . $socket
            : sprintf(
                'mysql:host=%s;port=%s',
                $this->firstHost($configuration['host'] ?? null),
                (string) ($configuration['port'] ?? '3306'),
            );
        $charset = $this->simpleIdentifier($configuration['charset'] ?? null);
        $dsn .= $charset === null ? '' : ';charset=' . $charset;
        $sql = 'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $database) . '`';
        $sql .= $charset === null ? '' : ' CHARACTER SET ' . $charset;
        $collation = $this->simpleIdentifier($configuration['collation'] ?? null);
        $sql .= $collation === null ? '' : ' COLLATE ' . $collation;

        $pdo = $this->pdo($dsn, $configuration);
        $statement = $pdo->prepare('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?');
        $statement->execute([$database]);

        $exists = $statement->fetchColumn() !== false;
        if (! $exists) {
            $pdo->exec($sql);
            $this->refresh($connectionName);
        }

        return $exists
            ? DatabaseProvisioningResult::Ready
            : DatabaseProvisioningResult::Created;
    }
}
