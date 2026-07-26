<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\Provisioners;

use Capell\Core\Contracts\Database\DatabaseProvisioner;

final class MySqlDatabaseProvisioner extends AbstractServerDatabaseProvisioner implements DatabaseProvisioner
{
    public function provision(string $connectionName, array $configuration): bool
    {
        $database = trim((string) ($configuration['database'] ?? ''));

        if ($database === '') {
            return false;
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

        $this->pdo($dsn, $configuration)->exec($sql);
        $this->refresh($connectionName);

        return true;
    }
}
