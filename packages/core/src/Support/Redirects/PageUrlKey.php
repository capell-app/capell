<?php

declare(strict_types=1);

namespace Capell\Core\Support\Redirects;

use Capell\Core\Exceptions\UnsupportedDatabaseDriver;
use Capell\Core\Facades\CapellDatabase;
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
        $name = $this->connection->getName();
        try {
            $dialect = CapellDatabase::for($this->connection)->schemaDialect();
            $cacheKey = $dialect::class . ':' . $name;
            self::$caseInsensitive[$cacheKey] ??= $dialect->isColumnCaseInsensitive((new PageUrl)->getTable(), 'url', $this->connection);
        } catch (UnsupportedDatabaseDriver) {
            return $url;
        } catch (Throwable) {
            return mb_strtolower($url);
        }

        return self::$caseInsensitive[$cacheKey] ? mb_strtolower($url) : $url;
    }

    public function taken(mixed $siteId, int $languageId, string $url): string
    {
        return $siteId . "\0" . $languageId . "\0" . $this->url($url);
    }
}
