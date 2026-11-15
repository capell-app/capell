<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use Symfony\Component\Finder\Finder;

/**
 * Finds authoring state, secrets, queries and cache access in a package's public Blade views.
 *
 * Every view under `resources/views` is public unless it sits in an admin directory (filament, admin, editor,
 * frontend-authoring, screenshots, mail). There is no per-view exemption: an admin view moves into an admin
 * directory, and a package whose public views must name authoring surfaces opts out of the whole check with a
 * reviewed decision record. Public views receive hydrated render data, so they must not name authoring surfaces,
 * signed editor URLs, record keys, secrets, query builders or the cache.
 */
final class PublicBladeSourceScanner
{
    /** @var list<string> */
    public const array FORBIDDEN_PATTERNS = [
        'authoring/regions',
        'CapellFrontendAuthoring',
        'capell-frontend-authoring',
        'data-capell-authoring',
        'signed-editor',
        'signed_editor',
        'signed editor',
        'edit_url',
        'recordKey',
        '::query(',
        'DB::',
        'Cache::',
        'loadMissing(',
        'client_secret',
        'webhook_secret',
        'private_key',
        'api_key',
    ];

    /** @var list<string> */
    private const array ADMIN_PATH_PARTS = [
        '/filament/',
        '/admin/',
        '/editor/',
        '/frontend-authoring/',
        '/screenshots/',
        '/mail/',
        '/livewire/filament/',
    ];

    /**
     * Violations as `resources/views/<file> contains <pattern>`, sorted.
     *
     * @return list<string>
     */
    public function violations(string $packageRoot): array
    {
        $viewsPath = rtrim($packageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views';

        if (! is_dir($viewsPath)) {
            return [];
        }

        $violations = [];

        foreach ((new Finder)->files()->in($viewsPath)->name('*.blade.php') as $file) {
            $relativePath = 'resources/views/' . str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
            $contents = $file->getContents();

            if (! $this->isPublicView($relativePath)) {
                continue;
            }

            foreach (self::FORBIDDEN_PATTERNS as $pattern) {
                if (str_contains($contents, $pattern)) {
                    $violations[] = $relativePath . ' contains ' . $pattern;
                }
            }

            // The helper reads or writes with arguments, or returns the cache repository without them.
            if (preg_match('/\bcache\s*\(/i', $contents) === 1) {
                $violations[] = $relativePath . ' contains cache()';
            }
        }

        sort($violations);

        return $violations;
    }

    public function isPublicView(string $relativePath): bool
    {
        $path = '/' . ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $relativePath), '/');

        return array_all(self::ADMIN_PATH_PARTS, fn (string $part): bool => ! str_contains($path, $part));
    }
}
