<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Install\VerifyCloudSiteBuildCompatibilityEnvelopeAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Capell\Core\Support\Install\ThemePackageCandidates;
use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Throwable;

class CloudBootstrapCommand extends Command
{
    protected $signature = 'capell:cloud-bootstrap';

    protected $description = 'Bootstrap and register a Capell install running on Laravel Cloud.';

    public function handle(): int
    {
        if ($this->cloudConfigString('install_mode') !== 'cloud') {
            $this->components->info('Capell Cloud bootstrap skipped outside cloud install mode.');

            return CommandAlias::SUCCESS;
        }

        $registrationUrl = $this->requiredConfigurationValue('registration_url', 'CAPELL_CLOUD_REGISTRATION_URL');
        $registrationToken = $this->requiredConfigurationValue('registration_token', 'CAPELL_REGISTRATION_TOKEN');
        $appUrl = rtrim($this->cloudConfigString('site_url'), '/');

        if ($registrationUrl === '' || $registrationToken === '') {
            return CommandAlias::FAILURE;
        }

        $installed = $this->isInstalled();

        if ($appUrl === '') {
            if (! $installed) {
                $this->components->info('Capell Cloud site URL not assigned yet; install deferred until CAPELL_SITE_URL is set.');
            }

            return CommandAlias::SUCCESS;
        }

        $instanceId = $this->instanceId($appUrl);
        $bootstrap = null;

        try {
            if (! $installed) {
                $bootstrap = $this->fetchBootstrapCredentials($registrationUrl, $registrationToken, $instanceId, $appUrl);

                if (! is_array($bootstrap) || (string) ($bootstrap['password'] ?? '') === '') {
                    $this->components->error('Capell Cloud bootstrap credentials were not available.');

                    return CommandAlias::FAILURE;
                }

                resolve(VerifyCloudSiteBuildCompatibilityEnvelopeAction::class)->handle(
                    $registrationToken,
                    is_array($bootstrap['_site_build_compatibility'] ?? null) ? $bootstrap['_site_build_compatibility'] : null,
                    $this->cloudConfigString('install_packages'),
                );
                $this->installCapell($appUrl, $bootstrap);
                $this->forceAdminPasswordChange($bootstrap);
            }

            $this->registerInstance($registrationUrl, $registrationToken, $instanceId, $appUrl);
        } catch (RequestException) {
            $this->components->error('Capell Cloud bootstrap failed while contacting Capell.');

            return CommandAlias::FAILURE;
        }

        $this->components->info('Capell Cloud bootstrap complete.');

        return CommandAlias::SUCCESS;
    }

    private function requiredConfigurationValue(string $key, string $environmentKey): string
    {
        $value = $this->cloudConfigString($key);

        if ($value === '') {
            $this->components->error(sprintf('%s is required for Capell Cloud bootstrap.', $environmentKey));
        }

        return $value;
    }

    private function cloudConfigString(string $key, string $default = ''): string
    {
        $value = config('capell.cloud.' . $key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Force the freshly provisioned cloud admin to replace their generated
     * bootstrap password on first login. Decoupled from the Password Policy
     * package: enables the forced-change policy and flags the admin only when
     * the package (settings class / users column) is actually installed, so it
     * is a safe no-op otherwise.
     *
     * @param  array<array-key, mixed>  $bootstrap
     */
    private function forceAdminPasswordChange(array $bootstrap): void
    {
        $email = (string) ($bootstrap['email'] ?? $this->cloudConfigString('admin_user.email'));

        if ($email === '') {
            return;
        }

        $settingsClass = 'Capell\\PasswordPolicy\\Settings\\PasswordPolicySettings';

        if (class_exists($settingsClass)) {
            try {
                $settings = resolve($settingsClass);

                if (property_exists($settings, 'force_change_enabled')) {
                    $settings->force_change_enabled = true;
                    $settings->save();
                }
            } catch (Throwable $throwable) {
                // Password Policy settings may not be migrated yet, which is a benign
                // skip. Log it rather than swallowing silently so an unexpected failure
                // (a genuine bug, not a missing migration) is still discoverable.
                Log::warning('Capell Cloud bootstrap could not enable forced password change.', [
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ]);
            }
        }

        $userModel = config('auth.providers.users.model');

        if (! is_string($userModel) || ! class_exists($userModel)) {
            return;
        }

        $user = new $userModel;

        if (! $user instanceof Model) {
            return;
        }

        $table = $user->getTable();

        if (! Schema::hasColumn($table, 'must_change_password')) {
            return;
        }

        $userModel::query()
            ->where('email', $email)
            ->update(['must_change_password' => true]);

        $this->components->info('Bootstrap admin must change password on first login.');
    }

    /** @return array<array-key, mixed>|null */
    private function fetchBootstrapCredentials(string $registrationUrl, string $registrationToken, string $instanceId, string $appUrl): ?array
    {
        $response = Http::acceptJson()
            ->timeout(20)
            ->post($registrationUrl, [
                'registration_token' => $registrationToken,
                'instance_id' => $instanceId,
                'webhook_url' => $this->webhookUrl($appUrl),
                'app_url' => $appUrl,
                'bootstrap_only' => true,
            ])
            ->throw();

        $bootstrap = $response->json('data.admin_bootstrap');

        if (is_array($bootstrap)) {
            $siteSpec = $response->json('data.site_spec');
            if (is_array($siteSpec)) {
                $bootstrap['_site_spec'] = $siteSpec;
            }
            $compatibility = $response->json('data.site_build_compatibility');
            $bootstrap['_site_build_compatibility'] = is_array($compatibility) ? $compatibility : null;
        }

        return is_array($bootstrap) ? $bootstrap : null;
    }

    /**
     * @param  array<array-key, mixed>  $bootstrap
     */
    private function installCapell(string $appUrl, array $bootstrap): void
    {
        $packages = $this->cloudConfigString('install_packages');

        $arguments = [
            '--url' => $appUrl,
            '--package-mode' => $packages !== '' ? 'custom' : 'core',
            '--theme' => $this->cloudConfigString('install_theme', 'default'),
            '--name' => (string) ($bootstrap['name'] ?? $this->cloudConfigString('admin_user.name', 'Admin')),
            '--email' => (string) ($bootstrap['email'] ?? $this->cloudConfigString('admin_user.email')),
            '--password' => (string) ($bootstrap['password'] ?? ''),
            '--clear-cache' => true,
            '--install-welcome-route' => true,
            '--no-interaction' => true,
        ];

        if ($packages !== '') {
            $arguments['--packages'] = $packages;
        }

        $siteSpec = $bootstrap['_site_spec'] ?? null;
        $specPath = null;

        try {
            if (is_array($siteSpec)) {
                $specPath = $this->createTemporarySiteSpec($siteSpec);
                $arguments['--spec'] = $specPath;
            }

            $exitCode = $this->call('capell:install', $arguments);
        } finally {
            if (is_string($specPath) && is_file($specPath)) {
                unlink($specPath);
            }
        }

        throw_if($exitCode !== CommandAlias::SUCCESS, RuntimeException::class, 'Capell installer failed during cloud bootstrap.');
    }

    /** @param array<string, mixed> $siteSpec */
    private function createTemporarySiteSpec(array $siteSpec): string
    {
        $contents = JsonCodec::encode($siteSpec, JSON_UNESCAPED_SLASHES);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'capell-site-spec-' . bin2hex(random_bytes(16));
            $handle = fopen($path, 'x+b');

            if ($handle === false) {
                continue;
            }

            try {
                throw_if(! chmod($path, 0600), RuntimeException::class, 'Unable to secure temporary Capell site spec.');
                $written = fwrite($handle, $contents);
                throw_if($written !== strlen($contents), RuntimeException::class, 'Unable to write temporary Capell site spec.');

                return $path;
            } catch (Throwable $throwable) {
                unlink($path);

                throw $throwable;
            } finally {
                fclose($handle);
            }
        }

        throw new RuntimeException('Unable to create temporary Capell site spec.');
    }

    private function registerInstance(string $registrationUrl, string $registrationToken, string $instanceId, string $appUrl): void
    {
        Http::acceptJson()
            ->timeout(20)
            ->post($registrationUrl, [
                'registration_token' => $registrationToken,
                'instance_id' => $instanceId,
                'webhook_url' => $this->webhookUrl($appUrl),
                'app_url' => $appUrl,
                'capell_version' => CapellCore::getInstalledPrettyVersion('capell-app/core'),
                'health' => $this->health($appUrl),
            ])
            ->throw();
    }

    private function isInstalled(): bool
    {
        return Schema::hasTable((new Site)->getTable())
            && Site::query()->exists();
    }

    private function instanceId(string $appUrl): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, implode('|', [
            (string) config('app.key'),
            $appUrl,
        ]))->toString();
    }

    private function webhookUrl(string $appUrl): string
    {
        if (Route::has('capell.marketplace.webhook')) {
            return $appUrl . '/' . ltrim(route('capell.marketplace.webhook', absolute: false), '/');
        }

        return $appUrl . '/capell/marketplace/webhook';
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private function health(string $appUrl): array
    {
        return [
            'installed' => $this->isInstalled(),
            'site_count' => Schema::hasTable((new Site)->getTable()) ? Site::query()->count() : 0,
            'app_url' => $appUrl,
            'capell_version' => CapellCore::getInstalledPrettyVersion('capell-app/core'),
            'marketplace_webhook_route' => Route::has('capell.marketplace.webhook'),
            'admin_route' => Route::has('filament.admin.pages.dashboard') || Route::has('filament.admin.auth.login'),
            'install_theme' => resolve(ThemePackageCandidates::class)->inputThemeKey($this->cloudConfigString('install_theme', 'default')) ?? 'default',
            'install_package_count' => count(array_filter(explode(',', $this->cloudConfigString('install_packages')))),
        ];
    }
}
