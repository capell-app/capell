<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Actions\GetPluginsAction;
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
     * Suites without their extension lists. Cheap: no marketplace lookup, so the browser installer
     * and `--recommendation` can call it on every request.
     *
     * @return list<InstallRecommendationData>
     */
    public function all(): array
    {
        return $this->resolve(withExtensions: false);
    }

    /**
     * Suites including `recommended` and `optional` extensions, each kept only if it is installed,
     * trusted core or listed as downloadable. That check may reach the marketplace, so only the
     * interactive CLI prompter asks for it.
     *
     * @return list<InstallRecommendationData>
     */
    public function suites(): array
    {
        return $this->resolve(withExtensions: true);
    }

    public function find(?string $key): ?InstallRecommendationData
    {
        if ($key === null || trim($key) === '') {
            return null;
        }

        return collect($this->all())->first(fn (InstallRecommendationData $recommendation): bool => $recommendation->key === $key);
    }

    /**
     * @return list<InstallRecommendationData>
     */
    private function resolve(bool $withExtensions): array
    {
        $recommendations = $this->configuredRecommendations();
        $available = CapellCore::getPackages(sortByDependencies: true);
        $downloadable = null;
        $isInstallable = function (string $package) use ($available, &$downloadable): bool {
            if ($available->has($package) || TrustedCorePackages::contains($package)) {
                return true;
            }

            $downloadable ??= $this->downloadablePackageNames();

            return in_array($package, $downloadable, true);
        };

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
            if ($label === '') {
                continue;
            }

            if ($description === '') {
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
                recommended: $withExtensions ? $this->reasonMap($recommendation['recommended'] ?? [], $isInstallable) : [],
                optional: $withExtensions ? $this->reasonMap($recommendation['optional'] ?? [], $isInstallable) : [],
            );
        }

        usort($resolved, static fn (InstallRecommendationData $left, InstallRecommendationData $right): int => [$left->order, $left->key] <=> [$right->order, $right->key]);

        return $resolved;
    }

    /**
     * Package names the marketplace lists as downloadable. Offline or unreachable, this is empty,
     * so a suite then offers only what is already installed rather than failing the install.
     *
     * @return list<string>
     */
    private function downloadablePackageNames(): array
    {
        try {
            return array_values(GetPluginsAction::run('download')->keys()->map(fn (mixed $name): string => (string) $name)->all());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  callable(string): bool  $isInstallable
     * @return array<string, string>
     */
    private function reasonMap(mixed $value, callable $isInstallable): array
    {
        if (! is_array($value)) {
            return [];
        }

        $reasons = [];
        foreach ($value as $package => $reason) {
            if (! is_string($package)) {
                continue;
            }

            $package = trim($package);
            if ($package === '') {
                continue;
            }

            if (! $isInstallable($package)) {
                continue;
            }

            $reasons[$package] = $this->stringValue($reason);
        }

        return $reasons;
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
