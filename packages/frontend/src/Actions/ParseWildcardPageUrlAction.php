<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Enums\UrlParamTypeEnum;
use Capell\Core\Models\PageUrl;
use Capell\Core\Support\Url\UrlPathNormalizer;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @phpstan-type UrlParts array<string, mixed>
 * @phpstan-type UrlParamSpec array<string, string>
 * @phpstan-type StringList list<string>
 * @phpstan-type IntList list<int>
 *
 * @method static UrlParts run(PageUrl $pageUrl, string $url, UrlParts $urlParts, ?string $paginationMode = null, UrlParts $options = [])
 */
class ParseWildcardPageUrlAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  UrlParts  $urlParts
     * @param  UrlParts  $options
     * @return UrlParts
     */
    public function handle(
        PageUrl $pageUrl,
        string $url,
        array $urlParts,
        ?string $paginationMode = null,
        array $options = [],
    ): array {
        $patternSegments = explode('/', trim($pageUrl->url, '/'));
        $urlSegments = explode('/', trim($url, '/'));
        $pageable = $pageUrl->pageable;

        if ($pageable instanceof Model && ! $pageable->relationLoaded('blueprint') && method_exists($pageable, 'blueprint')) {
            $pageable->loadMissing('blueprint');
        }

        $spec = $pageable->url_params ?? [];
        $hasSlug = array_key_exists('slug', $spec);
        $shouldEnforcePaginationMode = $this->shouldEnforcePaginationMode($spec, $hasSlug);
        $shouldEnforceInvalidPageValue = $shouldEnforcePaginationMode && (bool) ($options['enforceInvalidPageValue'] ?? false);
        $wildcardPositions = $this->collectWildcardPositions($patternSegments);
        $resolvedPaginationMode = $this->resolvePaginationMode($paginationMode);
        $paginationPageSegment = $this->resolvePageSegment();

        $specKeys = array_keys($spec);
        $urlParts['params'] = [];

        if (count($wildcardPositions) === 1) {
            $urlParts = $this->handleSingleWildcard($patternSegments, $urlSegments, $spec, $specKeys, $hasSlug, $wildcardPositions, $shouldEnforcePaginationMode, $shouldEnforceInvalidPageValue, $urlParts, $resolvedPaginationMode, $paginationPageSegment);
        } elseif (count($wildcardPositions) > 1) {
            $urlParts = $this->handleMultipleWildcards($patternSegments, $urlSegments, $spec, $hasSlug, $wildcardPositions, $urlParts);
        } elseif ($spec !== [] && str_starts_with($url, rtrim($pageUrl->url, '/') . '/')) {
            $urlParts = $this->handleNoWildcard($pageUrl, $url, $spec, $specKeys, $hasSlug, $shouldEnforcePaginationMode, $shouldEnforceInvalidPageValue, $urlParts, $resolvedPaginationMode, $paginationPageSegment);
        }

        if (! isset($urlParts['pageSlug'])) {
            $urlParts['pageSlug'] = $this->baseFromPattern($patternSegments);
        }

        return $urlParts;
    }

    private function coerce(string $raw, string $type): int|string|null
    {
        return UrlParamTypeEnum::coerceByType($raw, $type);
    }

    /**
     * @param  array<string,mixed>  $params
     */
    private function hasInvalidPageValue(array $params, bool $shouldEnforceInvalidPageValue): bool
    {
        if (! $shouldEnforceInvalidPageValue) {
            return false;
        }

        if (! array_key_exists('page', $params)) {
            return true;
        }

        $page = $params['page'];

        return ! is_int($page) || $page < 1;
    }

    /**
     * @param  StringList  $patternSegments
     * @param  StringList  $urlSegments
     */
    private function segmentsMatchPrefix(array $patternSegments, array $urlSegments, int $endExclusive): bool
    {
        for ($i = 0; $i < $endExclusive; $i++) {
            if (($patternSegments[$i] ?? null) !== ($urlSegments[$i] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  UrlParamSpec  $spec
     * @param  StringList  $segments
     * @param  UrlParts  $urlParts
     * @return UrlParts
     */
    private function collectLabelValueParams(array $spec, array $segments, array $urlParts): array
    {
        $specKeys = array_keys($spec);
        $counter = count($segments);
        for ($i = 0; $i + 1 < $counter; $i += 2) {
            $label = $segments[$i];
            $valueRaw = $segments[$i + 1];
            if (! in_array($label, $specKeys, true) || ! array_key_exists($label, $spec)) {
                break;
            }

            $coerced = $this->coerce($valueRaw, $spec[$label]);
            if ($coerced !== null) {
                $urlParts['params'][$label] = $coerced;
            }
        }

        return $urlParts;
    }

    /**
     * @param  UrlParamSpec  $spec
     * @param  StringList  $segments
     * @return StringList
     */
    private function normalizePaginationSegments(
        array $spec,
        array $segments,
        string $paginationMode,
        string $paginationPageSegment,
    ): array {
        if (! array_key_exists('page', $spec) || $segments === []) {
            return $segments;
        }

        $firstSegment = $segments[0];

        if ($paginationMode === 'normal' && $firstSegment === $paginationPageSegment) {
            $segments[0] = 'page';

            return $segments;
        }

        if (in_array($paginationMode, ['dash', 'dashed'], true)) {
            $prefix = $paginationPageSegment . '-';
            if (str_starts_with($firstSegment, $prefix)) {
                $pageValue = substr($firstSegment, strlen($prefix));

                return array_merge(['page', $pageValue], array_slice($segments, 1));
            }
        }

        return $segments;
    }

    /**
     * @param  StringList  $segments
     * @param  UrlParts  $urlParts
     * @return UrlParts
     */
    private function markInvalidPaginationWhenNeeded(
        array $segments,
        bool $shouldEnforceInvalidPageValue,
        string $paginationMode,
        string $paginationPageSegment,
        array $urlParts,
    ): array {
        if (! $shouldEnforceInvalidPageValue) {
            return $urlParts;
        }

        if (! $this->matchesPaginationMode($segments, $paginationMode, $paginationPageSegment)) {
            return $urlParts;
        }

        if ($this->hasInvalidPageValue($urlParts['params'] ?? [], $shouldEnforceInvalidPageValue)) {
            $urlParts['invalidPagination'] = true;
        }

        return $urlParts;
    }

    /**
     * @param  StringList  $segments
     */
    private function matchesPaginationMode(
        array $segments,
        string $paginationMode,
        string $paginationPageSegment,
    ): bool {
        if ($segments === []) {
            return false;
        }

        if ($paginationMode === 'simple') {
            return count($segments) === 1;
        }

        if ($paginationMode === 'normal') {
            return count($segments) === 2 && $segments[0] === $paginationPageSegment;
        }

        if (in_array($paginationMode, ['dash', 'dashed'], true)) {
            return count($segments) === 1 && str_starts_with($segments[0], $paginationPageSegment . '-');
        }

        return false;
    }

    /**
     * @param  UrlParamSpec  $spec
     * @param  StringList  $segments
     * @param  UrlParts  $urlParts
     * @return UrlParts
     */
    private function collectOrderedParams(array $spec, array $segments, array $urlParts): array
    {
        $specKeys = array_keys($spec);
        $ordered = array_values(array_filter($specKeys, fn (string $key): bool => $key !== 'slug'));

        foreach ($ordered as $i => $key) {
            if (! isset($segments[$i])) {
                break;
            }

            $coerced = $this->coerce($segments[$i], $spec[$key]);
            if ($coerced !== null) {
                $urlParts['params'][$key] = $coerced;
            }
        }

        return $urlParts;
    }

    /**
     * @param  StringList  $segments
     * @param  StringList  $specKeys
     * @return StringList
     */
    private function removeLeadingSlug(bool $hasSlug, array $segments, array $specKeys): array
    {
        if ($hasSlug && isset($segments[0]) && ! in_array($segments[0], $specKeys, true)) {
            return array_slice($segments, 1);
        }

        return $segments;
    }

    /**
     * @param  StringList  $patternSegments
     * @return IntList
     */
    private function collectWildcardPositions(array $patternSegments): array
    {
        $positions = [];
        foreach ($patternSegments as $i => $seg) {
            if ($seg === '*') {
                $positions[] = $i;
            }
        }

        return $positions;
    }

    /**
     * @param  UrlParamSpec  $spec
     */
    private function shouldEnforcePaginationMode(array $spec, bool $hasSlug): bool
    {
        $nonSlugKeys = array_values(array_filter(array_keys($spec), fn (string $key): bool => $key !== 'slug'));

        return ! $hasSlug && $nonSlugKeys === ['page'] && (($spec['page'] ?? '') === 'int');
    }

    private function resolvePaginationMode(?string $paginationMode): string
    {
        $mode = strtolower(trim($paginationMode ?? config('paginateroute.mode', 'normal')));

        return in_array($mode, ['normal', 'simple', 'dash', 'dashed'], true) ? $mode : 'normal';
    }

    private function resolvePageSegment(): string
    {
        $translatedSegment = trans('paginateroute::paginateroute.page');

        if (! is_string($translatedSegment) || $translatedSegment === '' || $translatedSegment === 'paginateroute::paginateroute.page') {
            return 'page';
        }

        return trim($translatedSegment);
    }

    /**
     * @param  StringList  $patternSegments
     * @param  StringList  $urlSegments
     * @param  UrlParamSpec  $spec
     * @param  StringList  $specKeys
     * @param  IntList  $wildcardPositions
     * @param  UrlParts  $urlParts
     * @return UrlParts
     */
    private function handleSingleWildcard(
        array $patternSegments,
        array $urlSegments,
        array $spec,
        array $specKeys,
        bool $hasSlug,
        array $wildcardPositions,
        bool $shouldEnforcePaginationMode,
        bool $shouldEnforceInvalidPageValue,
        array $urlParts,
        string $paginationMode,
        string $paginationPageSegment,
    ): array {
        $baseIndex = $wildcardPositions[0];
        if (! $this->segmentsMatchPrefix($patternSegments, $urlSegments, $baseIndex)) {
            return $urlParts;
        }

        $remaining = array_slice($urlSegments, $baseIndex);
        $urlParts['params'] = [];

        if ($shouldEnforcePaginationMode
            && ! $this->matchesPaginationMode($remaining, $paginationMode, $paginationPageSegment)
        ) {
            return $urlParts;
        }

        $remaining = $this->normalizePaginationSegments($spec, $remaining, $paginationMode, $paginationPageSegment);

        $startsWithLabel = isset($remaining[0]) && in_array($remaining[0], $specKeys, true);

        if (! $hasSlug && $startsWithLabel && count($remaining) >= 2) {
            return $this->collectLabelValueParams($spec, $remaining, $urlParts);
        }

        if ($hasSlug && isset($remaining[0]) && $remaining[0] !== '') {
            $remaining = array_slice($remaining, 1);
        }

        if (count($remaining) >= 2 && in_array($remaining[0], $specKeys, true)) {
            $label = $remaining[0];
            $valueRaw = $remaining[1];
            if (array_key_exists($label, $spec)) {
                $coerced = $this->coerce($valueRaw, $spec[$label]);
                if ($coerced !== null) {
                    $urlParts['params'][$label] = $coerced;
                }
            }

            return $urlParts;
        }

        $orderedKeys = array_values(array_filter($specKeys, fn (string $key): bool => $key !== 'slug'));
        if (count($remaining) !== count($orderedKeys)) {
            return $urlParts;
        }

        foreach ($orderedKeys as $idx => $key) {
            if (! isset($remaining[$idx])) {
                break;
            }

            $coerced = $this->coerce($remaining[$idx], $spec[$key]);
            if ($coerced !== null) {
                $urlParts['params'][$key] = $coerced;
            }
        }

        return $this->markInvalidPaginationWhenNeeded($remaining, $shouldEnforceInvalidPageValue, $paginationMode, $paginationPageSegment, $urlParts);
    }

    /**
     * @param  StringList  $patternSegments
     * @param  StringList  $urlSegments
     * @param  UrlParamSpec  $spec
     * @param  IntList  $wildcardPositions
     * @param  UrlParts  $urlParts
     * @return UrlParts
     */
    private function handleMultipleWildcards(
        array $patternSegments,
        array $urlSegments,
        array $spec,
        bool $hasSlug,
        array $wildcardPositions,
        array $urlParts,
    ): array {
        if (count($patternSegments) !== count($urlSegments)) {
            return $urlParts;
        }

        foreach ($patternSegments as $i => $seg) {
            if ($seg === '*') {
                continue;
            }

            if ($seg !== ($urlSegments[$i] ?? null)) {
                return $urlParts;
            }
        }

        $values = [];
        foreach ($wildcardPositions as $pos) {
            $values[] = $urlSegments[$pos] ?? '';
        }

        $urlParts['params'] = [];
        if ($hasSlug && isset($values[0]) && $values[0] !== '') {
            $values = array_slice($values, 1);
        }

        $keys = array_values(array_filter(array_keys($spec), fn (int|string $k): bool => $k !== 'slug'));
        if (count($values) === count($keys)) {
            foreach ($values as $i => $raw) {
                $coerced = $this->coerce($raw, $spec[$keys[$i]]);
                if ($coerced !== null) {
                    $urlParts['params'][$keys[$i]] = $coerced;
                }
            }
        }

        return $urlParts;
    }

    /**
     * @param  UrlParamSpec  $spec
     * @param  StringList  $specKeys
     * @param  UrlParts  $urlParts
     * @return UrlParts
     */
    private function handleNoWildcard(
        PageUrl $pageUrl,
        string $url,
        array $spec,
        array $specKeys,
        bool $hasSlug,
        bool $shouldEnforcePaginationMode,
        bool $shouldEnforceInvalidPageValue,
        array $urlParts,
        string $paginationMode,
        string $paginationPageSegment,
    ): array {
        $remainder = trim(UrlPathNormalizer::stripPrefix($url, $pageUrl->url), '/');
        $segments = $remainder === '' ? [] : explode('/', $remainder);
        $urlParts['params'] = [];

        if ($shouldEnforcePaginationMode
            && ! $this->matchesPaginationMode($segments, $paginationMode, $paginationPageSegment)
        ) {
            return $urlParts;
        }

        $segments = $this->normalizePaginationSegments($spec, $segments, $paginationMode, $paginationPageSegment);

        $segments = $this->removeLeadingSlug($hasSlug, $segments, $specKeys);

        if (count($segments) >= 2 && in_array($segments[0], $specKeys, true)) {
            return $this->markInvalidPaginationWhenNeeded($segments, $shouldEnforceInvalidPageValue, $paginationMode, $paginationPageSegment, $this->collectLabelValueParams($spec, $segments, $urlParts));
        }

        return $this->markInvalidPaginationWhenNeeded($segments, $shouldEnforceInvalidPageValue, $paginationMode, $paginationPageSegment, $this->collectOrderedParams($spec, $segments, $urlParts));
    }

    /**
     * @param  StringList  $patternSegments
     */
    private function baseFromPattern(array $patternSegments): string
    {
        $baseSegs = array_filter($patternSegments, fn (string $seg): bool => $seg !== '*');

        return '/' . implode('/', $baseSegs);
    }
}
