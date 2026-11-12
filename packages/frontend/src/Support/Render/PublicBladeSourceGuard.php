<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Rejects query and relationship calls in registered public Blade views when the
 * view is compiled, before any request renders it.
 *
 * Public Blade receives hydrated render data only. The runtime query guard
 * catches a query after it ran; this guard names the offending call and view the
 * moment the template is compiled, including views contributed by themes and
 * extensions that register their directory with registerRoot().
 */
final class PublicBladeSourceGuard
{
    private const array FORBIDDEN_PATTERNS = [
        'Eloquent query builder' => '/::query\s*\(/',
        'DB facade' => '/\bDB::/',
        'cache facade' => '/\bCache::/',
        'auth access' => '/\bauth\s*\(/',
        'lazy relationship loading' => '/->load(?:Missing)?\s*\(/',
        'direct model lookup' => '/::(?:find|findOrFail|first|firstOrFail)\s*\(/',
    ];

    /** @var list<string> */
    private array $roots = [];

    public function registerRoot(string $directory): void
    {
        $root = realpath($directory);

        if ($root !== false && ! in_array($root, $this->roots, true)) {
            $this->roots[] = $root;
        }
    }

    /** @return list<string> Labels of the forbidden calls found in the source. */
    public function violations(string $source): array
    {
        return array_keys(array_filter(
            self::FORBIDDEN_PATTERNS,
            static fn (string $pattern): bool => preg_match($pattern, $source) === 1,
        ));
    }

    public function inspect(string $source, ?string $path): string
    {
        if ($path === null || ! $this->isPublicView($path) || ! $this->enabled()) {
            return $source;
        }

        $violations = $this->violations($source);

        if ($violations === []) {
            return $source;
        }

        $message = sprintf(
            'Public Blade view %s calls %s. Public views receive hydrated render data only; load it before rendering. To disable this check set capell-frontend.public_view_query_guard.enabled=false.',
            $path,
            implode(', ', $violations),
        );

        if ($this->mode() === 'log') {
            Log::warning('capell-frontend: ' . $message);

            return $source;
        }

        throw new RuntimeException($message);
    }

    private function isPublicView(string $path): bool
    {
        $real = realpath($path);

        return $real !== false && array_any($this->roots, static fn (string $root): bool => str_starts_with($real, $root . DIRECTORY_SEPARATOR));
    }

    private function enabled(): bool
    {
        return config('capell-frontend.public_view_query_guard.enabled', true) !== false;
    }

    private function mode(): string
    {
        return config('capell-frontend.public_view_query_guard.mode', 'exception') === 'log' ? 'log' : 'exception';
    }
}
