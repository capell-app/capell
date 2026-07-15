<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Capell\Frontend\Contracts\FrontendResourceSourceData;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

final class PublicResourceSourceData extends Data implements FrontendResourceSourceData
{
    public readonly string $path;

    public function __construct(string $path)
    {
        if (str_starts_with($path, '//') || preg_match('/\A[a-z][a-z0-9+.-]*:/i', $path) === 1) {
            throw new InvalidArgumentException('Public resource path must be a safe local path.');
        }

        $path = ltrim($path, '/');

        if ($path === '' || str_contains($path, '\\') || preg_match('#(^|/)\.\.(/|$)#', $path) === 1 || filter_var($path, FILTER_VALIDATE_URL) !== false) {
            throw new InvalidArgumentException('Public resource path must be a safe local path.');
        }

        $this->path = $path;
    }
}
