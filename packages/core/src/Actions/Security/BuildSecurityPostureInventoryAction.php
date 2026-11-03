<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Security;

use Capell\Core\Actions\Diagnostics\ResolveCapellInstallationStateAction;
use Capell\Core\Contracts\Security\SecurityAdvisorySource;
use Capell\Core\Contracts\Security\SecurityInventoryContributor;
use Capell\Core\Data\Security\SecurityInventoryPackageData;
use Capell\Core\Data\Security\SecurityPostureInventoryData;
use Capell\Core\Enums\Diagnostics\CapellInstallationState;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

class BuildSecurityPostureInventoryAction
{
    use AsFake;
    use AsObject;

    /** Prefixes Core owns in the inventory; contributors must use their own. */
    private const array CORE_PREFIXES = ['app', 'session', 'installer', 'installation', 'queue', 'cache', 'capell'];

    public function handle(): SecurityPostureInventoryData
    {
        $packages = CapellExtension::query()->get()->map(static function (CapellExtension $extension): SecurityInventoryPackageData {
            $slug = $extension->metadata['slug'] ?? null;

            return new SecurityInventoryPackageData(
                composerName: $extension->composer_name,
                version: $extension->version,
                slug: is_string($slug) ? $slug : null,
                autoUpdatePolicy: $extension->auto_update_policy->value,
                trusted: TrustedCorePackages::contains($extension->composer_name),
                enabled: $extension->status === ExtensionStatusEnum::Enabled,
            );
        })->all();

        $advisories = [];
        $failures = [];
        $sourcesRun = 0;
        foreach (app()->tagged(SecurityAdvisorySource::TAG) as $source) {
            $sourcesRun++;
            try {
                array_push($advisories, ...$source->advisories());
            } catch (Throwable) {
                // Do not persist exception text, which may contain credentials.
                $failures[] = 'advisory source: ' . $source::class;
            }
        }

        $url = config('app.url');
        $scheme = is_string($url) ? parse_url($url, PHP_URL_SCHEME) : null;
        $key = config('app.key');
        $environment = [
            'app.debug' => config('app.debug') === true,
            'app.env' => (string) config('app.env', ''),
            'app.url_scheme' => is_string($scheme) ? strtolower($scheme) : '',
            'app.key_present' => is_string($key) && $key !== '',
            'session.secure_cookie' => config('session.secure') === true,
            'session.http_only' => config('session.http_only') === true,
            'session.same_site' => (string) config('session.same_site', ''),
            'installer.enabled' => config('capell-installer.enabled') === null ? null : config('capell-installer.enabled') === true,
            'installation.complete' => ResolveCapellInstallationStateAction::run() === CapellInstallationState::Installed,
            'queue.default' => (string) config('queue.default', ''),
            'cache.default' => (string) config('cache.default', ''),
            'capell.multi_node' => config('capell.multi_node') === true,
        ];

        foreach (app()->tagged(SecurityInventoryContributor::TAG) as $contributor) {
            try {
                $environment = $this->mergeContributedFlags($environment, $contributor, $failures);
            } catch (Throwable) {
                // Do not persist exception text, which may contain credentials.
                $failures[] = 'inventory contributor: ' . $contributor::class;
            }
        }

        return new SecurityPostureInventoryData(
            capellVersion: CapellCore::getInstalledPrettyVersion('capell-app/capell'),
            installedPackages: array_values($packages),
            environment: $environment,
            advisories: $advisories,
            collectedAt: CarbonImmutable::now(),
            advisorySourcesRun: $sourcesRun,
            failures: $failures,
        );
    }

    /**
     * Merge one contributor's flags under its prefix. A contributor may not
     * reuse a Core prefix or another contributor's keys, and may only supply
     * scalars; violations drop the contributor and record a failure so the
     * scan is reported as incomplete rather than silently narrower.
     *
     * @param  array<string, bool|int|float|string|null>  $environment
     * @param  list<string>  $failures
     * @return array<string, bool|int|float|string|null>
     */
    private function mergeContributedFlags(array $environment, SecurityInventoryContributor $contributor, array &$failures): array
    {
        $prefix = $contributor->prefix();

        if (preg_match('/^[a-z][a-z0-9_]*$/', $prefix) !== 1 || in_array($prefix, self::CORE_PREFIXES, true)) {
            $failures[] = 'inventory contributor prefix: ' . $contributor::class;

            return $environment;
        }

        $merged = $environment;

        foreach ($contributor->flags() as $key => $value) {
            $fullKey = $prefix . '.' . $key;

            if (! is_string($key) || $key === '' || ! is_scalar($value) && $value !== null || array_key_exists($fullKey, $environment)) {
                $failures[] = 'inventory contributor flags: ' . $contributor::class;

                return $environment;
            }

            $merged[$fullKey] = $value;
        }

        return $merged;
    }
}
