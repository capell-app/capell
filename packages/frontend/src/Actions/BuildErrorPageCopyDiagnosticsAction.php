<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Data\ErrorPageCopyDiagnosticsData;
use Capell\Frontend\Data\ErrorPageCopySourceData;
use Capell\Frontend\Support\Error\ErrorPageFallbackManifestStore;
use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Resolve, read-only, which of the competing sources supplies each piece of
 * error-page copy for a host and status.
 *
 * A single 404 headline has at least five plausible sources and an undocumented
 * precedence order, and the winner is not the obvious one: the pre-rendered
 * fallback manifest beats the blade's own `@section`, which beats the package
 * default translation. Core also ships two near-duplicate 404 strings that look
 * authoritative and are never consulted here. Reporting the whole ladder in
 * order is the only way to stop the guessing.
 *
 * Read-only by construction: reads the fallback manifest, reads the error view
 * source, resolves translation keys. It renders nothing and writes nothing, so
 * it is safe against production and cannot itself trigger the regeneration it
 * reports on.
 *
 * @method static ErrorPageCopyDiagnosticsData run(string $host, string $status)
 */
final class BuildErrorPageCopyDiagnosticsAction
{
    use AsFake;
    use AsObject;

    /** Copy fields the error blade resolves through the same ladder. */
    private const array FIELDS = ['headline', 'description'];

    public function __construct(
        private readonly ErrorPageFallbackManifestStore $fallbackManifestStore,
    ) {}

    public function handle(string $host, string $status): ErrorPageCopyDiagnosticsData
    {
        $normalizedHost = strtolower($host);
        $manifestPath = $this->fallbackManifestStore->path();
        $manifestExists = File::exists($manifestPath);
        $manifest = $this->fallbackManifestStore->read();
        $viewPath = $this->viewPath($status);

        $fields = [];
        $winners = [];

        foreach (self::FIELDS as $field) {
            $sources = $this->sources($manifest, $normalizedHost, $status, $field, $viewPath);
            $fields[$field] = $this->applyPrecedence($sources);
            $winners[$field] = $this->winningValue($fields[$field]);
        }

        return new ErrorPageCopyDiagnosticsData(
            host: $normalizedHost,
            status: $status,
            fallbackManifestPath: $manifestPath,
            fallbackManifestExists: $manifestExists,
            fallbackManifestWrittenAt: $this->writtenAt($manifestPath),
            viewPath: $viewPath,
            fields: $fields,
            winners: $winners,
        );
    }

    /**
     * The candidate sources in the order the blade evaluates them.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<ErrorPageCopySourceData>
     */
    private function sources(array $manifest, string $host, string $status, string $field, ?string $viewPath): array
    {
        $hostValue = $this->manifestValue($manifest, ['hosts', $host, 'copy', $status, $field]);
        $defaultValue = $this->manifestValue($manifest, ['default', 'copy', $status, $field]);
        $sectionKey = $viewPath === null ? null : $this->sectionTranslationKey($viewPath, $field);
        $sectionValue = $sectionKey === null ? null : $this->translation($sectionKey);
        $packageDefaultKey = sprintf('capell-frontend::errors.default_%s', $field);
        $seedKey = sprintf('capell::generic.error_%s_%s', $status, $field);

        $sources = [
            new ErrorPageCopySourceData(
                order: 1,
                source: sprintf('fallback manifest: hosts.%s.copy.%s.%s', $host, $status, $field),
                present: $hostValue !== null,
                value: $hostValue,
            ),
            new ErrorPageCopySourceData(
                order: 2,
                source: sprintf('fallback manifest: default.copy.%s.%s', $status, $field),
                present: $defaultValue !== null,
                value: $defaultValue,
            ),
            new ErrorPageCopySourceData(
                order: 3,
                source: $sectionKey !== null
                    ? sprintf('errors::%s @section(%s) -> %s', $status, $field, $sectionKey)
                    : sprintf('errors::%s @section(%s)', $status, $field),
                present: $sectionValue !== null,
                value: $sectionValue,
            ),
            new ErrorPageCopySourceData(
                order: 4,
                source: $packageDefaultKey,
                present: true,
                value: $this->translation($packageDefaultKey),
            ),
            new ErrorPageCopySourceData(
                order: 5,
                source: $seedKey,
                present: $this->translation($seedKey) !== null,
                value: $this->translation($seedKey),
                skippedBecause: 'not_consulted_at_render_time',
                consulted: false,
            ),
        ];

        if ($field === 'headline' && $status === '404') {
            $sources[] = new ErrorPageCopySourceData(
                order: 6,
                source: 'capell::generic.page_not_found',
                present: $this->translation('capell::generic.page_not_found') !== null,
                value: $this->translation('capell::generic.page_not_found'),
                skippedBecause: 'not_consulted_at_render_time',
                consulted: false,
            );
        }

        return $sources;
    }

    /**
     * Mark the first present, consulted source as the winner and say exactly
     * why each of the others lost.
     *
     * @param  list<ErrorPageCopySourceData>  $sources
     * @return list<ErrorPageCopySourceData>
     */
    private function applyPrecedence(array $sources): array
    {
        $winnerOrder = null;
        $resolved = [];

        foreach ($sources as $source) {
            if (! $source->consulted) {
                $resolved[] = $source;

                continue;
            }

            if (! $source->present) {
                $resolved[] = new ErrorPageCopySourceData(
                    order: $source->order,
                    source: $source->source,
                    present: false,
                    value: $source->value,
                    skippedBecause: 'absent',
                );

                continue;
            }

            if ($winnerOrder === null) {
                $winnerOrder = $source->order;
                $resolved[] = new ErrorPageCopySourceData(
                    order: $source->order,
                    source: $source->source,
                    present: true,
                    value: $source->value,
                    won: true,
                );

                continue;
            }

            $resolved[] = new ErrorPageCopySourceData(
                order: $source->order,
                source: $source->source,
                present: true,
                value: $source->value,
                skippedBecause: sprintf('outranked_by_order_%d', $winnerOrder),
            );
        }

        return $resolved;
    }

    /** @param list<ErrorPageCopySourceData> $sources */
    private function winningValue(array $sources): ?string
    {
        foreach ($sources as $source) {
            if ($source->won) {
                return $source->value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $keys
     */
    private function manifestValue(array $manifest, array $keys): ?string
    {
        $current = $manifest;

        foreach ($keys as $key) {
            if (! is_array($current) || ! array_key_exists($key, $current)) {
                return null;
            }

            $current = $current[$key];
        }

        return (is_string($current) && $current !== '') ? $current : null;
    }

    /**
     * Locate the status blade the same way Laravel does.
     *
     * The `errors::` namespace is registered by
     * `RegisterErrorViewPaths` only while an exception is being rendered, so it
     * does not exist in a console context. Resolving `{path}/errors/{status}`
     * against `view.paths` applies the identical rule without needing an
     * exception in flight.
     */
    private function viewPath(string $status): ?string
    {
        /** @var list<string> $paths */
        $paths = (array) config('view.paths', []);

        foreach ($paths as $path) {
            $candidate = rtrim((string) $path, '/') . sprintf('/errors/%s.blade.php', $status);

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Read the translation key the status blade yields for this field.
     *
     * Reading the blade source is deliberate: rendering it would execute the
     * very error view being diagnosed.
     */
    private function sectionTranslationKey(string $viewPath, string $field): ?string
    {
        $contents = File::get($viewPath);
        $pattern = sprintf("/@section\\(\\s*'%s'\\s*,\\s*__\\(\\s*'([^']+)'/", preg_quote($field, '/'));

        if (preg_match($pattern, $contents, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function translation(string $key): ?string
    {
        $value = (string) __($key);

        return ($value === '' || $value === $key) ? null : $value;
    }

    private function writtenAt(string $path): ?string
    {
        if (! File::exists($path)) {
            return null;
        }

        $timestamp = File::lastModified($path);

        return date(DATE_ATOM, $timestamp);
    }
}
