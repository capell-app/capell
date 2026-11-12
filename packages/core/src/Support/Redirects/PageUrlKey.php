<?php

declare(strict_types=1);

namespace Capell\Core\Support\Redirects;

use Capell\Core\Data\Database\ColumnComparison;
use Capell\Core\Exceptions\UnsupportedDatabaseDriver;
use Capell\Core\Facades\CapellDatabase;
use Capell\Core\Models\PageUrl;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Normalizer;
use Throwable;

final class PageUrlKey
{
    private ?ColumnComparison $comparison = null;

    private readonly Connection $connection;

    public function __construct(?Connection $connection = null)
    {
        $this->connection = $connection ?? DB::connection((new PageUrl)->getConnectionName());
    }

    public function url(string $url): string
    {
        try {
            $this->comparison ??= CapellDatabase::for($this->connection)->schemaDialect()
                ->columnComparison((new PageUrl)->getTable(), 'url', $this->connection);
        } catch (UnsupportedDatabaseDriver) {
            return $url;
        } catch (Throwable) {
            $this->comparison = ColumnComparison::widest();
        }

        $key = $url;

        if ($this->comparison->padSpace) {
            $key = rtrim($key, ' ');
        }

        if ($this->comparison->accentInsensitive) {
            $key = $this->withoutAccents($key);
        }

        return $this->comparison->caseInsensitive ? mb_strtolower($key) : $key;
    }

    public function taken(mixed $siteId, int $languageId, string $url): string
    {
        return $siteId . "\0" . $languageId . "\0" . $this->url($url);
    }

    private function withoutAccents(string $value): string
    {
        if (! class_exists(Normalizer::class)) {
            return $value;
        }

        $decomposed = Normalizer::normalize($value, Normalizer::FORM_D);

        if (! is_string($decomposed)) {
            return $value;
        }

        return Normalizer::normalize(preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $decomposed, Normalizer::FORM_C) ?: $value;
    }
}
