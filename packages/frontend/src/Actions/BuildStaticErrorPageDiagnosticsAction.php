<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Contracts\StaticErrorPageStore;
use Capell\Frontend\Data\StaticErrorPageCandidateData;
use Capell\Frontend\Data\StaticErrorPageDiagnosticsData;
use Capell\Frontend\Enums\StaticErrorPageResolutionReason;
use Capell\Frontend\Support\Error\ErrorPageManifestStore;
use Capell\Frontend\Support\Error\StaticErrorPageMatcher;
use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Explain, without changing anything, what
 * {@see ResolveStaticErrorPageAction} would do for a given request.
 *
 * Read-only by construction: it reads the manifest, asks the store for paths
 * and stats files. It never writes, generates or purges, so it is safe to run
 * against production.
 *
 * @method static StaticErrorPageDiagnosticsData run(string $scheme, string $host, string $pathInfo, string $status)
 */
final class BuildStaticErrorPageDiagnosticsAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly StaticErrorPageMatcher $matcher,
        private readonly ErrorPageManifestStore $manifestStore,
    ) {}

    public function handle(string $scheme, string $host, string $pathInfo, string $status): StaticErrorPageDiagnosticsData
    {
        $storeBound = app()->bound(StaticErrorPageStore::class);
        $manifestPath = $this->manifestStore->path();
        $requestPath = $this->matcher->normalizePath($pathInfo);
        $entries = $this->matcher->entries();

        $candidates = [];
        $bestIndex = null;
        $bestMatchLength = -1;
        $closestRejection = null;

        foreach ($entries as $index => $entry) {
            $rejection = $this->matcher->reject($entry, $scheme, $host, $pathInfo, $status);

            $candidates[$index] = new StaticErrorPageCandidateData(
                index: $index,
                scheme: $this->matcher->entryScheme($entry),
                domain: $this->matcher->entryDomain($entry),
                status: $this->matcher->entryStatus($entry),
                path: $this->matcher->entryPath($entry),
                file: $this->matcher->entryFile($entry),
                rejectedBy: $rejection,
                expected: $rejection instanceof StaticErrorPageResolutionReason ? $this->expected($rejection, $entry) : null,
                actual: $rejection instanceof StaticErrorPageResolutionReason ? $this->actual($rejection, $scheme, $host, $requestPath, $status) : null,
            );

            if ($rejection instanceof StaticErrorPageResolutionReason) {
                if (! $closestRejection instanceof StaticErrorPageResolutionReason || $rejection->specificity() > $closestRejection->specificity()) {
                    $closestRejection = $rejection;
                }

                continue;
            }

            $matchLength = $this->matcher->matchLength($this->matcher->entryPath($entry));

            if ($matchLength > $bestMatchLength) {
                $bestIndex = $index;
                $bestMatchLength = $matchLength;
            }
        }

        if (! $storeBound) {
            return $this->report($scheme, $host, $requestPath, $status, false, $manifestPath, $candidates, StaticErrorPageResolutionReason::StoreUnbound);
        }

        if ($entries === []) {
            return $this->report($scheme, $host, $requestPath, $status, true, $manifestPath, $candidates, StaticErrorPageResolutionReason::ManifestEmpty);
        }

        if ($bestIndex === null) {
            return $this->report($scheme, $host, $requestPath, $status, true, $manifestPath, $candidates, $closestRejection ?? StaticErrorPageResolutionReason::ManifestEmpty);
        }

        $selected = $candidates[$bestIndex];
        $resolvedPath = resolve(StaticErrorPageStore::class)->path($selected->file);
        $fileExists = $resolvedPath !== null && is_file($resolvedPath);

        $reason = match (true) {
            $resolvedPath === null => StaticErrorPageResolutionReason::FileUnresolved,
            ! $fileExists => StaticErrorPageResolutionReason::FileMissing,
            default => null,
        };

        $candidates[$bestIndex] = new StaticErrorPageCandidateData(
            index: $selected->index,
            scheme: $selected->scheme,
            domain: $selected->domain,
            status: $selected->status,
            path: $selected->path,
            file: $selected->file,
            rejectedBy: $reason,
            selected: true,
            resolvedPath: $resolvedPath,
            fileExists: $fileExists,
        );

        return $this->report(
            $scheme,
            $host,
            $requestPath,
            $status,
            true,
            $manifestPath,
            $candidates,
            $reason,
            $resolvedPath,
            $fileExists,
        );
    }

    /** @param array<int, StaticErrorPageCandidateData> $candidates */
    private function report(
        string $scheme,
        string $host,
        string $requestPath,
        string $status,
        bool $storeBound,
        string $manifestPath,
        array $candidates,
        ?StaticErrorPageResolutionReason $reason,
        ?string $resolvedFilePath = null,
        ?bool $resolvedFileExists = null,
    ): StaticErrorPageDiagnosticsData {
        return new StaticErrorPageDiagnosticsData(
            scheme: strtolower($scheme),
            host: strtolower($host),
            path: $requestPath,
            status: $status,
            storeBound: $storeBound,
            manifestPath: $manifestPath,
            manifestExists: File::exists($manifestPath),
            candidates: array_values($candidates),
            resolved: ! $reason instanceof StaticErrorPageResolutionReason,
            reason: $reason,
            resolvedFilePath: $resolvedFilePath,
            resolvedFileExists: $resolvedFileExists,
        );
    }

    /** @param array<string, mixed> $entry */
    private function expected(StaticErrorPageResolutionReason $reason, array $entry): ?string
    {
        return match ($reason) {
            StaticErrorPageResolutionReason::SchemeMismatch => $this->matcher->entryScheme($entry),
            StaticErrorPageResolutionReason::DomainMismatch => $this->matcher->entryDomain($entry),
            StaticErrorPageResolutionReason::StatusMismatch => $this->matcher->entryStatus($entry),
            StaticErrorPageResolutionReason::PathMismatch => $this->matcher->entryPath($entry),
            default => null,
        };
    }

    private function actual(StaticErrorPageResolutionReason $reason, string $scheme, string $host, string $requestPath, string $status): ?string
    {
        return match ($reason) {
            StaticErrorPageResolutionReason::SchemeMismatch => strtolower($scheme),
            StaticErrorPageResolutionReason::DomainMismatch => strtolower($host),
            StaticErrorPageResolutionReason::StatusMismatch => $status,
            StaticErrorPageResolutionReason::PathMismatch => $requestPath,
            default => null,
        };
    }
}
