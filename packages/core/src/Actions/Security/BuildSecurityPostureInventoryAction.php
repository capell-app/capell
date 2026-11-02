<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Security;

use Capell\Core\Actions\Diagnostics\ResolveCapellInstallationStateAction;
use Capell\Core\Contracts\Security\SecurityAdvisorySource;
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

        return new SecurityPostureInventoryData(
            capellVersion: CapellCore::getInstalledPrettyVersion('capell-app/capell'),
            installedPackages: array_values($packages),
            environment: [
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
            ],
            advisories: $advisories,
            collectedAt: CarbonImmutable::now(),
            advisorySourcesRun: $sourcesRun,
            failures: $failures,
        );
    }
}
