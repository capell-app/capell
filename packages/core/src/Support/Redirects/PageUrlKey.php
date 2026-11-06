<?php

declare(strict_types=1);

namespace Capell\Core\Support\Redirects;

use Capell\Core\Models\PageUrl;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PageUrlKey
{
    /** @var array<string, bool> */
    private static array $caseInsensitive = [];

    private readonly Connection $connection;

    public function __construct(?Connection $connection = null)
    {
        $this->connection = $connection ?? DB::connection((new PageUrl)->getConnectionName());
    }

    public function url(string $url): string
    {
        if (! in_array($this->connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $url;
        }

        $name = $this->connection->getName();
        self::$caseInsensitive[$name] ??= $this->readCaseInsensitive();

        return self::$caseInsensitive[$name] ? mb_strtolower($url) : $url;
    }

    public function taken(mixed $siteId, int $languageId, string $url): string
    {
        return $siteId . "\0" . $languageId . "\0" . $this->url($url);
    }

    private function readCaseInsensitive(): bool
    {
        try {
            $database = $this->connection->getDatabaseName();
            $table = $this->connection->getTablePrefix() . (new PageUrl)->getTable();
            $row = $this->connection->selectOne(
                'select (select COLLATION_NAME from information_schema.COLUMNS where TABLE_SCHEMA = ? and TABLE_NAME = ? and COLUMN_NAME = ?) as column_collation, (select TABLE_COLLATION from information_schema.TABLES where TABLE_SCHEMA = ? and TABLE_NAME = ?) as table_collation',
                [$database, $table, 'url', $database, $table],
            );
            $collation = $row->column_collation ?? $row->table_collation ?? null;

            return ! is_string($collation) || str_ends_with(strtolower($collation), '_ci');
        } catch (Throwable) {
            return true;
        }
    }
}
