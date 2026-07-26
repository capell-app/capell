<?php

declare(strict_types=1);

namespace Capell\Core\Support\Database\Provisioners;

use Illuminate\Support\Facades\DB;
use PDO;

abstract class AbstractServerDatabaseProvisioner
{
    /**
     * @param  array<string, mixed>  $configuration
     */
    protected function pdo(string $dsn, array $configuration): PDO
    {
        $options = $configuration['options'] ?? [];
        $pdo = new PDO(
            $dsn,
            (string) ($configuration['username'] ?? ''),
            (string) ($configuration['password'] ?? ''),
            is_array($options) ? $options : [],
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    protected function refresh(string $connectionName): void
    {
        DB::purge($connectionName);
        DB::reconnect($connectionName);
    }

    protected function firstHost(mixed $host): string
    {
        if (is_array($host)) {
            $host = reset($host);
        }

        return is_string($host) && $host !== '' ? $host : '127.0.0.1';
    }

    protected function simpleIdentifier(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\\w+$/', $value) === 1 ? $value : null;
    }
}
