<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Links;

use Illuminate\Support\Facades\Route;
use Throwable;

final readonly class PublicLinkResolver
{
    public function __construct(
        private PublicRouteAliasRegistry $routeAliases,
    ) {}

    /**
     * @param  array<string, mixed>|null  $link
     */
    public function resolve(?array $link): string
    {
        if ($link === null) {
            return '#';
        }

        $anchor = isset($link['anchor']) && is_string($link['anchor']) && $link['anchor'] !== ''
            ? '#' . ltrim($link['anchor'], '#')
            : '';
        $queryString = $this->queryStringFor($link);
        $route = is_string($link['route'] ?? null) ? $link['route'] : '';

        if ($route !== '' && $this->routeAliases->has($route)) {
            return $this->safeHref($this->appendQuery((string) $this->routeAliases->resolve($route), $queryString), $anchor);
        }

        if ($route !== '' && Route::has($route)) {
            $routeParameters = is_array($link['parameters'] ?? null) ? $link['parameters'] : [];

            try {
                return $this->safeHref($this->appendQuery(route($route, $routeParameters), $queryString), $anchor);
            } catch (Throwable) {
                // Required route parameters were not supplied. Fall back to an
                // explicit href (or anchor) below rather than throwing and
                // taking the whole page/fragment render down with it.
            }
        }

        $href = is_string($link['href'] ?? null) ? $this->appendQuery($link['href'], $queryString) : null;

        return $this->safeHref($href, $anchor);
    }

    public function safeHref(?string $href, string $anchor = ''): string
    {
        $href = trim((string) $href);

        if ($href === '') {
            return $anchor !== '' ? $anchor : '#';
        }

        if (str_starts_with($href, '#')) {
            return $href;
        }

        $isSafeRelativePath = str_starts_with($href, '/')
            && ! str_starts_with($href, '//')
            && ! str_starts_with($href, '/\\');

        if ($isSafeRelativePath || preg_match('#^https?://#i', $href) === 1 || preg_match('#^mailto:[^\s@]+@[^\s@]+$#i', $href) === 1) {
            return $href . $anchor;
        }

        return $anchor !== '' ? $anchor : '#';
    }

    /**
     * @param  array<string, mixed>  $link
     */
    private function queryStringFor(array $link): string
    {
        $query = $link['query'] ?? null;

        if (is_string($query)) {
            $query = trim($query);

            return $query === '' ? '' : '?' . http_build_query(['search' => $query], '', '&', PHP_QUERY_RFC3986);
        }

        if (! is_array($query)) {
            return '';
        }

        $query = array_filter(
            $query,
            static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '',
        );

        return $query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function appendQuery(string $url, string $queryString): string
    {
        if ($queryString === '') {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' . ltrim($queryString, '?') : $queryString);
    }
}
