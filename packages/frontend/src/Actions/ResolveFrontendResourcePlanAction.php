<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Frontend\Data\Assets\ExternalResourceSourceData;
use Capell\Frontend\Data\Assets\FrontendResourceActivationData;
use Capell\Frontend\Data\Assets\FrontendResourceActivationPlanData;
use Capell\Frontend\Data\Assets\FrontendResourceContributionData;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\Assets\FrontendResourceHintData;
use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Data\Assets\InlineResourceSourceData;
use Capell\Frontend\Data\Assets\PublicResourceSourceData;
use Capell\Frontend\Data\Assets\ResolvedFrontendResourceData;
use Capell\Frontend\Data\Assets\ViteResourceSourceData;
use Capell\Frontend\Enums\ExternalResourceIntegrityPolicy;
use Capell\Frontend\Enums\FrontendResourceHintAs;
use Capell\Frontend\Enums\FrontendResourceHintKind;
use Capell\Frontend\Enums\FrontendResourceKind;
use Capell\Frontend\Enums\FrontendResourcePlacement;
use Capell\Frontend\Enums\FrontendResourceSourceKind;
use Capell\Frontend\Exceptions\FrontendResourcePlanException;
use Illuminate\Foundation\Vite;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class ResolveFrontendResourcePlanAction
{
    use AsObject;

    public function __construct(
        private readonly UrlGenerator $url,
        private readonly Vite $vite,
    ) {}

    /**
     * @param  array<int, FrontendResourceContributionData>  $contributions
     * @param  array<int, FrontendResourceHintData>  $declaredHints
     */
    public function handle(array $contributions, array $declaredHints = []): FrontendResourcePlanData
    {
        try {
            return $this->resolvePlan($contributions, $declaredHints);
        } catch (Throwable $exception) {
            if (! app()->environment('production')) {
                throw $exception instanceof FrontendResourcePlanException
                    ? $exception
                    : new FrontendResourcePlanException('Unable to resolve the frontend resource plan: ' . $exception->getMessage(), previous: $exception);
            }

            $diagnostic = [
                'code' => 'frontend-resource-plan-invalid',
                'severity' => 'error',
                'message' => $exception->getMessage(),
            ];

            Log::error('Capell omitted an invalid frontend resource graph.', $diagnostic);

            return new FrontendResourcePlanData(
                headResources: [],
                bodyEndResources: [],
                lazyActivationGraphs: [],
                hints: [],
                aliases: [],
                diagnostics: [$diagnostic],
                cspOrigins: ['script-src' => [], 'style-src' => [], 'connect-src' => [], 'font-src' => []],
                fingerprint: hash('sha256', json_encode($diagnostic, JSON_THROW_ON_ERROR)),
            );
        }
    }

    /**
     * @param  array<int, FrontendResourceContributionData>  $contributions
     * @param  array<int, FrontendResourceHintData>  $declaredHints
     */
    private function resolvePlan(array $contributions, array $declaredHints): FrontendResourcePlanData
    {
        $declarations = $this->declarations($contributions);
        $diagnostics = $this->integrityDiagnostics($declarations);
        $activations = $this->activations($contributions);
        $ordered = $this->topologicalOrder($declarations);
        $resolved = [];
        $canonicalHandles = [];
        $canonicalSignatures = [];
        $aliases = [];
        $resolvedByHandle = [];
        $hints = $this->typedHints($declaredHints);
        $viteHints = [];
        $expandedParents = [];
        $expandedResourcesByParent = [];

        foreach ($ordered as $declaration) {
            [$expandedResources, $expandedHints] = $this->expand($declaration);
            $viteHints[$declaration->handle] = $expandedHints;

            foreach ($expandedResources as $resource) {
                $expandedParents[$resource->handle] = $declaration->handle;
                $canonicalKey = $resource->kind->value . ':' . ($resource->url ?? hash('sha256', (string) $resource->content));
                $signature = $this->compatibilitySignature($resource);

                if (isset($canonicalHandles[$canonicalKey])) {
                    if ($canonicalSignatures[$canonicalKey] !== $signature) {
                        throw new FrontendResourcePlanException("Conflicting frontend resource declarations resolve to [{$canonicalKey}].");
                    }

                    $aliases[$resource->handle] = $canonicalHandles[$canonicalKey];
                    $resolvedByHandle[$resource->handle] = $resolvedByHandle[$canonicalHandles[$canonicalKey]];
                    $expandedResourcesByParent[$declaration->handle][$canonicalHandles[$canonicalKey]] = $resolvedByHandle[$canonicalHandles[$canonicalKey]];

                    continue;
                }

                $canonicalHandles[$canonicalKey] = $resource->handle;
                $canonicalSignatures[$canonicalKey] = $signature;
                $resolved[] = $resource;
                $resolvedByHandle[$resource->handle] = $resource;
                $expandedResourcesByParent[$declaration->handle][$resource->handle] = $resource;
            }
        }

        $eagerHandles = $this->eagerHandles($declarations, $activations);
        foreach (array_keys($eagerHandles) as $eagerHandle) {
            $hints = [...$hints, ...($viteHints[$eagerHandle] ?? [])];
        }
        $hints = $this->typedHints($hints);
        $eagerCanonicalHandles = array_fill_keys(array_map(static fn (string $handle): string => $aliases[$handle] ?? $handle, array_keys($eagerHandles)), true);
        $head = array_values(array_filter($resolved, static fn (ResolvedFrontendResourceData $resource): bool => isset($eagerCanonicalHandles[$aliases[$expandedParents[$resource->handle]] ?? $expandedParents[$resource->handle]]) && $resource->placement === FrontendResourcePlacement::Head));
        $bodyEnd = array_values(array_filter($resolved, static fn (ResolvedFrontendResourceData $resource): bool => isset($eagerCanonicalHandles[$aliases[$expandedParents[$resource->handle]] ?? $expandedParents[$resource->handle]]) && $resource->placement === FrontendResourcePlacement::BodyEnd));
        $lazyActivationGraphs = $this->lazyActivationGraphs($activations, $declarations, $resolvedByHandle, $aliases, $eagerHandles, $expandedResourcesByParent);
        $cspOrigins = $this->cspOrigins($resolved, $hints);
        $fingerprint = hash('sha256', json_encode([
            'resources' => array_map($this->fingerprintResource(...), $resolved),
            'activations' => array_map(static fn (FrontendResourceActivationPlanData $activation): array => [
                $activation->target,
                $activation->loadingStrategy->value,
                array_map(static fn (array $layer): array => array_map(static fn (ResolvedFrontendResourceData $resource): string => $resource->handle, $layer), $activation->dependencyLayers),
            ], $lazyActivationGraphs),
            'hints' => array_map(static fn (FrontendResourceHintData $hint): array => $hint->toArray(), $hints),
            'aliases' => $aliases,
            'csp' => $cspOrigins,
        ], JSON_THROW_ON_ERROR));

        return new FrontendResourcePlanData($head, $bodyEnd, $lazyActivationGraphs, $hints, $aliases, $diagnostics, $cspOrigins, $fingerprint);
    }

    /**
     * @param  array<int, FrontendResourceContributionData>  $contributions
     * @return array<string, FrontendResourceData>
     */
    private function declarations(array $contributions): array
    {
        $declarations = [];

        foreach ($contributions as $contribution) {
            if (! $contribution instanceof FrontendResourceContributionData) {
                throw new FrontendResourcePlanException('The frontend resource plan accepts only typed contributions.');
            }

            $resource = $contribution->resource;

            if (isset($declarations[$resource->handle]) && $declarations[$resource->handle] !== $resource) {
                throw new FrontendResourcePlanException("Conflicting frontend resource handle [{$resource->handle}].");
            }

            $declarations[$resource->handle] = $resource;
        }

        return $declarations;
    }

    /**
     * @param  array<int, FrontendResourceHintData>  $hints
     * @return array<int, FrontendResourceHintData>
     */
    private function typedHints(array $hints): array
    {
        $typed = [];

        foreach ($hints as $hint) {
            if (! $hint instanceof FrontendResourceHintData) {
                throw new FrontendResourcePlanException('The frontend resource plan accepts only typed resource hints.');
            }

            $key = implode('|', [$hint->kind->value, $hint->href, $hint->as?->value ?? '', $hint->mimeType ?? '', $hint->crossOrigin?->value ?? '', $hint->fetchPriority?->value ?? '']);
            $typed[$key] = $hint;
        }

        return array_values($typed);
    }

    /**
     * @param  array<string, FrontendResourceData>  $declarations
     * @return array<int, array<string, mixed>>
     */
    private function integrityDiagnostics(array $declarations): array
    {
        $policy = ExternalResourceIntegrityPolicy::tryFrom((string) config('capell-frontend.external_resources.integrity_policy', 'warn'))
            ?? ExternalResourceIntegrityPolicy::Warn;
        $diagnostics = [];

        foreach ($declarations as $resource) {
            if (! $resource->source instanceof ExternalResourceSourceData || $resource->source->integrity !== null || $policy === ExternalResourceIntegrityPolicy::Off) {
                continue;
            }

            if ($policy === ExternalResourceIntegrityPolicy::Require) {
                throw new FrontendResourcePlanException("External frontend resource [{$resource->handle}] requires an integrity hash.");
            }

            $diagnostics[] = [
                'code' => 'external-integrity-missing',
                'severity' => 'warning',
                'handle' => $resource->handle,
                'package' => $resource->package,
                'url' => $resource->source->httpsUrl,
            ];
        }

        return $diagnostics;
    }

    /**
     * @param  array<int, FrontendResourceContributionData>  $contributions
     * @return array<string, array<int, FrontendResourceActivationData>>
     */
    private function activations(array $contributions): array
    {
        $activations = [];

        foreach ($contributions as $contribution) {
            if (! $contribution instanceof FrontendResourceContributionData) {
                continue;
            }

            $activations[$contribution->resource->handle] ??= [];
            $activations[$contribution->resource->handle] = [...$activations[$contribution->resource->handle], ...$contribution->activations];
        }

        return $activations;
    }

    /**
     * @param  array<string, FrontendResourceData>  $declarations
     * @param  array<string, array<int, FrontendResourceActivationData>>  $activations
     * @return array<string, true>
     */
    private function eagerHandles(array $declarations, array $activations): array
    {
        $eager = [];
        $promote = function (string $handle) use (&$promote, &$eager, $declarations): void {
            if (isset($eager[$handle])) {
                return;
            }

            $eager[$handle] = true;

            foreach ($declarations[$handle]->dependsOn as $dependency) {
                $promote($dependency);
            }
        };

        foreach ($declarations as $handle => $resource) {
            $resourceActivations = $activations[$handle] ?? [];
            $isEager = $resourceActivations === [];

            foreach ($resourceActivations as $activation) {
                $isEager = $isEager || $activation->loadingStrategy === PresentationLoadingStrategy::Eager;
            }

            if ($isEager) {
                $promote($handle);
            }
        }

        return $eager;
    }

    /**
     * @param  array<string, array<int, FrontendResourceActivationData>>  $activations
     * @param  array<string, FrontendResourceData>  $declarations
     * @param  array<string, ResolvedFrontendResourceData>  $resolvedByHandle
     * @param  array<string, string>  $aliases
     * @param  array<string, true>  $eagerHandles
     * @param  array<string, array<string, ResolvedFrontendResourceData>>  $expandedResourcesByParent
     * @return array<int, FrontendResourceActivationPlanData>
     */
    private function lazyActivationGraphs(array $activations, array $declarations, array $resolvedByHandle, array $aliases, array $eagerHandles, array $expandedResourcesByParent): array
    {
        $roots = [];

        foreach ($activations as $handle => $resourceActivations) {
            if (isset($eagerHandles[$handle])) {
                continue;
            }

            foreach ($resourceActivations as $activation) {
                if ($activation->loadingStrategy === PresentationLoadingStrategy::Eager) {
                    continue;
                }

                $key = $activation->target . '|' . $activation->loadingStrategy->value;
                $roots[$key] ??= ['activation' => $activation, 'handles' => []];
                $roots[$key]['handles'][$handle] = $handle;
            }
        }

        $graphs = [];

        foreach ($roots as $root) {
            $depths = [];
            $visit = function (string $handle) use (&$visit, &$depths, $declarations, $eagerHandles): int {
                if (isset($eagerHandles[$handle])) {
                    return -1;
                }

                if (isset($depths[$handle])) {
                    return $depths[$handle];
                }

                $depth = 0;

                foreach ($declarations[$handle]->dependsOn as $dependency) {
                    $depth = max($depth, $visit($dependency) + 1);
                }

                return $depths[$handle] = $depth;
            };

            foreach ($root['handles'] as $handle) {
                $visit($handle);
            }

            $layers = [];

            foreach ($depths as $handle => $depth) {
                $canonicalHandle = $aliases[$handle] ?? $handle;
                $expanded = $expandedResourcesByParent[$handle] ?? [$canonicalHandle => $resolvedByHandle[$canonicalHandle]];

                foreach ($expanded as $expandedHandle => $expandedResource) {
                    $layers[$depth][$expandedHandle] = $expandedResource;
                }
            }

            ksort($layers);
            $layers = array_map(array_values(...), array_values($layers));
            $graphs[] = new FrontendResourceActivationPlanData($root['activation']->target, $root['activation']->loadingStrategy, $layers);
        }

        return $graphs;
    }

    /**
     * @param  array<string, FrontendResourceData>  $declarations
     * @return array<int, FrontendResourceData>
     */
    private function topologicalOrder(array $declarations): array
    {
        $ordered = [];
        $visiting = [];
        $visited = [];

        $visit = function (string $handle) use (&$visit, &$ordered, &$visiting, &$visited, $declarations): void {
            if (isset($visited[$handle])) {
                return;
            }

            if (isset($visiting[$handle])) {
                throw new FrontendResourcePlanException("Frontend resource dependency cycle detected at [{$handle}].");
            }

            $resource = $declarations[$handle] ?? throw new FrontendResourcePlanException("Missing frontend resource dependency [{$handle}].");
            $visiting[$handle] = true;

            foreach ($resource->dependsOn as $dependency) {
                $dependencyResource = $declarations[$dependency] ?? throw new FrontendResourcePlanException("Missing frontend resource dependency [{$dependency}] required by [{$handle}].");

                if ($dependencyResource->async) {
                    throw new FrontendResourcePlanException("Async resources cannot satisfy dependencies: [{$dependency}].");
                }

                $visit($dependency);
            }

            unset($visiting[$handle]);
            $visited[$handle] = true;
            $ordered[] = $resource;
        };

        foreach (array_keys($declarations) as $handle) {
            $visit($handle);
        }

        return $ordered;
    }

    private function resolve(FrontendResourceData $resource): ResolvedFrontendResourceData
    {
        $url = null;
        $content = null;
        $integrity = null;
        $crossOrigin = null;
        $referrerPolicy = null;
        $sourceKind = null;
        $localPath = null;

        if ($resource->source instanceof PublicResourceSourceData) {
            $url = $this->url->asset($resource->source->path);
            $sourceKind = FrontendResourceSourceKind::PublicPath;
            $localPath = public_path(ltrim($resource->source->path, '/'));
        } elseif ($resource->source instanceof ExternalResourceSourceData) {
            $url = $resource->source->httpsUrl;
            $integrity = $resource->source->integrity;
            $crossOrigin = $resource->source->crossOrigin;
            $referrerPolicy = $resource->source->referrerPolicy;
            $sourceKind = FrontendResourceSourceKind::External;
        } elseif ($resource->source instanceof InlineResourceSourceData) {
            $content = $resource->source->content;
            $sourceKind = FrontendResourceSourceKind::Inline;
        }

        return new ResolvedFrontendResourceData(
            token: substr(hash('sha256', $resource->handle), 0, 24),
            handle: $resource->handle,
            package: $resource->package,
            kind: $resource->kind,
            url: $url,
            content: $content,
            placement: $resource->placement,
            dependsOn: $resource->dependsOn,
            criticalCssEligible: $resource->criticalCssEligible,
            executionMode: $resource->executionMode,
            defer: $resource->defer,
            async: $resource->async,
            integrity: $integrity,
            crossOrigin: $crossOrigin,
            referrerPolicy: $referrerPolicy,
            sourceKind: $sourceKind,
            localPath: $localPath,
        );
    }

    /**
     * @return array{array<int, ResolvedFrontendResourceData>, array<int, FrontendResourceHintData>}
     */
    private function expand(FrontendResourceData $resource): array
    {
        if (! $resource->source instanceof ViteResourceSourceData) {
            return [[$this->resolve($resource)], []];
        }

        $source = $resource->source;

        if ($this->vite->isRunningHot()) {
            $client = $this->resolvedViteResource(
                resource: $resource,
                handle: $resource->handle . ':vite-client',
                kind: FrontendResourceKind::ModuleScript,
                url: $this->vite->asset('@vite/client', $source->buildDirectory),
                localPath: null,
            );
            $entry = $this->resolvedViteResource(
                resource: $resource,
                handle: $resource->handle,
                kind: $resource->kind,
                url: $this->vite->asset($source->entry, $source->buildDirectory),
                localPath: null,
            );

            return [[$client, $entry], []];
        }

        $manifestPath = public_path(trim($source->buildDirectory, '/') . '/manifest.json');

        if (! is_file($manifestPath)) {
            throw new FrontendResourcePlanException("Vite manifest not found for frontend resource [{$resource->handle}] at [{$manifestPath}].");
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($manifest) || ! isset($manifest[$source->entry]) || ! is_array($manifest[$source->entry])) {
            throw new FrontendResourcePlanException("Vite entry [{$source->entry}] is missing for frontend resource [{$resource->handle}].");
        }

        $entry = $manifest[$source->entry];
        $cssFiles = [];
        $importFiles = [];
        $visited = [];
        $collect = function (array $chunk) use (&$collect, &$cssFiles, &$importFiles, &$visited, $manifest): void {
            foreach (($chunk['css'] ?? []) as $css) {
                if (is_string($css)) {
                    $cssFiles[$css] = $css;
                }
            }

            foreach (($chunk['imports'] ?? []) as $import) {
                if (! is_string($import) || isset($visited[$import]) || ! isset($manifest[$import]) || ! is_array($manifest[$import])) {
                    continue;
                }

                $visited[$import] = true;
                $importChunk = $manifest[$import];

                if (isset($importChunk['file']) && is_string($importChunk['file'])) {
                    $importFiles[$importChunk['file']] = $importChunk['file'];
                }

                $collect($importChunk);
            }
        };
        $collect($entry);
        $resources = [];

        foreach ($cssFiles as $css) {
            $resources[] = $this->resolvedViteResource(
                resource: $resource,
                handle: $resource->handle . ':css:' . substr(hash('sha256', $css), 0, 12),
                kind: FrontendResourceKind::Style,
                url: $this->url->asset(trim($source->buildDirectory, '/') . '/' . ltrim($css, '/')),
                localPath: public_path(trim($source->buildDirectory, '/') . '/' . ltrim($css, '/')),
            );
        }

        $entryFile = $entry['file'] ?? null;

        if (! is_string($entryFile) || $entryFile === '') {
            throw new FrontendResourcePlanException("Vite entry [{$source->entry}] has no output file.");
        }

        $resources[] = $this->resolvedViteResource(
            resource: $resource,
            handle: $resource->handle,
            kind: $resource->kind,
            url: $this->url->asset(trim($source->buildDirectory, '/') . '/' . ltrim($entryFile, '/')),
            localPath: public_path(trim($source->buildDirectory, '/') . '/' . ltrim($entryFile, '/')),
        );
        $hints = array_map(
            fn (string $file): FrontendResourceHintData => new FrontendResourceHintData(
                FrontendResourceHintKind::ModulePreload,
                $this->url->asset(trim($source->buildDirectory, '/') . '/' . ltrim($file, '/')),
                FrontendResourceHintAs::Script,
            ),
            array_values($importFiles),
        );

        return [$resources, $hints];
    }

    private function resolvedViteResource(FrontendResourceData $resource, string $handle, FrontendResourceKind $kind, string $url, ?string $localPath): ResolvedFrontendResourceData
    {
        return new ResolvedFrontendResourceData(
            token: substr(hash('sha256', $handle), 0, 24),
            handle: $handle,
            package: $resource->package,
            kind: $kind,
            url: $url,
            content: null,
            placement: $kind === FrontendResourceKind::Style ? FrontendResourcePlacement::Head : $resource->placement,
            dependsOn: $handle === $resource->handle ? $resource->dependsOn : [],
            criticalCssEligible: $kind === FrontendResourceKind::Style && $resource->criticalCssEligible,
            executionMode: $kind->isScript() ? $resource->executionMode : null,
            defer: $kind === FrontendResourceKind::ClassicScript && $resource->defer,
            async: $kind->isScript() && $resource->async,
            sourceKind: FrontendResourceSourceKind::Vite,
            localPath: $localPath,
        );
    }

    private function compatibilitySignature(ResolvedFrontendResourceData $resource): string
    {
        return hash('sha256', json_encode([
            $resource->integrity,
            $resource->crossOrigin?->value,
            $resource->referrerPolicy?->value,
            $resource->placement->value,
            $resource->executionMode?->value,
            $resource->defer,
            $resource->async,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<int, ResolvedFrontendResourceData>  $resources
     * @param  array<int, FrontendResourceHintData>  $hints
     */
    private function cspOrigins(array $resources, array $hints): array
    {
        $origins = ['script-src' => [], 'style-src' => [], 'connect-src' => [], 'font-src' => []];

        foreach ($resources as $resource) {
            if ($resource->url === null) {
                continue;
            }

            $parts = parse_url($resource->url);

            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
                continue;
            }

            $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $directive = match ($resource->kind) {
                FrontendResourceKind::Style, FrontendResourceKind::InlineStyle => 'style-src',
                default => 'script-src',
            };
            $origins[$directive][$origin] = $origin;
        }

        foreach ($hints as $hint) {
            $parts = parse_url($hint->href);

            if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
                continue;
            }

            $origin = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $directive = match (true) {
                $hint->kind === FrontendResourceHintKind::Preconnect => 'connect-src',
                $hint->as === FrontendResourceHintAs::Font => 'font-src',
                $hint->as === FrontendResourceHintAs::Style => 'style-src',
                default => 'script-src',
            };
            $origins[$directive][$origin] = $origin;
        }

        return array_map(array_values(...), $origins);
    }

    /** @return array<string, mixed> */
    private function fingerprintResource(ResolvedFrontendResourceData $resource): array
    {
        return [
            'handle' => $resource->handle,
            'kind' => $resource->kind->value,
            'url' => $resource->url,
            'content' => $resource->content,
            'integrity' => $resource->integrity,
            'crossOrigin' => $resource->crossOrigin?->value,
            'referrerPolicy' => $resource->referrerPolicy?->value,
            'dependsOn' => $resource->dependsOn,
            'placement' => $resource->placement->value,
            'executionMode' => $resource->executionMode?->value,
            'defer' => $resource->defer,
            'async' => $resource->async,
            'criticalCssEligible' => $resource->criticalCssEligible,
        ];
    }
}
