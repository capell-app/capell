<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Data\Install\InstallRecommendationData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Json\JsonCodec;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Reads deterministic, host-overridable install bundles.
 *
 * Recommendations are deliberately data, not package heuristics. A host can
 * provide them through `capell.install.recommendations`, a PHP config file, or
 * a JSON file. Unknown package names are discarded so a stale recommendation
 * can never make Composer require an unregistered package.
 */
final class InstallRecommendationRepository
{
    /**
     * @return list<InstallRecommendationData>
     */
    public function all(): array
    {
        $recommendations = $this->configuredRecommendations();
        $available = CapellCore::getPackages(sortByDependencies: true);

        $resolved = [];
        foreach ($recommendations as $key => $recommendation) {
            if (! is_array($recommendation)) {
                continue;
            }

            $packages = $this->stringList($recommendation['packages'] ?? []);
            $packages = array_values(array_filter(
                $packages,
                fn (string $package): bool => $available->has($package) || TrustedCorePackages::contains($package),
            ));

            $label = $this->stringValue($recommendation['label'] ?? null);
            $description = $this->stringValue($recommendation['description'] ?? null);
            if ($label === '' || $description === '') {
                continue;
            }

            $resolved[] = new InstallRecommendationData(
                key: (string) $key,
                label: $label,
                description: $description,
                packages: array_values(array_unique($packages)),
                theme: $this->nullableString($recommendation['theme'] ?? null),
                demo: is_bool($recommendation['demo'] ?? null) ? $recommendation['demo'] : null,
                order: is_int($recommendation['order'] ?? null) ? $recommendation['order'] : 0,
            );
        }

        usort($resolved, static fn (InstallRecommendationData $left, InstallRecommendationData $right): int => [$left->order, $left->key] <=> [$right->order, $right->key]);

        return $resolved;
    }

    public function find(?string $key): ?InstallRecommendationData
    {
        if ($key === null || trim($key) === '') {
            return null;
        }

        return collect($this->all())->first(fn (InstallRecommendationData $recommendation): bool => $recommendation->key === $key);
    }

    /** @return array<string, array<string, mixed>> */
    private function configuredRecommendations(): array
    {
        $configured = config('capell.install.recommendations');
        if (is_array($configured)) {
            return $this->normaliseConfiguredRecommendations($configured);
        }

        $phpPath = base_path('config/capell-install-recommendations.php');
        if (File::exists($phpPath)) {
            $recommendations = require $phpPath;
            if (is_array($recommendations)) {
                return $this->normaliseConfiguredRecommendations($recommendations);
            }
        }

        $jsonPath = base_path('capell-install-recommendations.json');
        if (! File::exists($jsonPath)) {
            return [];
        }

        try {
            return $this->normaliseConfiguredRecommendations(JsonCodec::decodeArray((string) File::get($jsonPath)));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string|int, mixed>  $recommendations
     * @return array<string, array<string, mixed>>
     */
    private function normaliseConfiguredRecommendations(array $recommendations): array
    {
        $normalised = [];
        foreach ($recommendations as $key => $recommendation) {
            if (is_string($key) && is_array($recommendation)) {
                $normalised[$key] = $recommendation;
            }
        }

        return $normalised;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return is_array($value)
            ? array_values(array_filter(array_map(fn (mixed $item): string => trim((string) $item), $value), fn (string $item): bool => $item !== ''))
            : [];
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = $this->stringValue($value);

        return $value !== '' ? $value : null;
    }
}
