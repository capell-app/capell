<?php

declare(strict_types=1);

namespace Capell\Core\Support\Bootstrap;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Settings\CoreSettings;
use Capell\Core\ThemeStudio\Settings\ThemeStudioSettings;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use ReflectionClass;
use Spatie\LaravelSettings\LaravelSettingsServiceProvider;

final readonly class SettingsBootstrapper
{
    public function __construct(
        private Application $app,
        private Repository $config,
    ) {}

    public function bootstrap(): void
    {
        if (! $this->app instanceof CachesConfiguration || ! $this->app->configurationIsCached()) {
            $providerFile = new ReflectionClass(LaravelSettingsServiceProvider::class)->getFileName();

            if ($providerFile !== false) {
                $settingsConfigPath = dirname($providerFile) . '/../config/settings.php';

                if (is_file($settingsConfigPath)) {
                    $this->config->set('settings', array_merge(
                        require $settingsConfigPath,
                        $this->config->get('settings', []),
                    ));
                }
            }
        }

        $this->config->set('settings.settings', $this->settingsClasses());
    }

    /**
     * The configured settings classes plus Capell's own and every package's, which is the list
     * Spatie Laravel Settings loads. Commands read it directly, so they see the same classes
     * whether or not the configuration has been rebuilt since a package was installed.
     *
     * @return list<string>
     */
    public function settingsClasses(): array
    {
        $settings = (array) $this->config->get('settings.settings', []);

        foreach ([CoreSettings::class, ThemeStudioSettings::class] as $settingsClass) {
            if (! in_array($settingsClass, $settings, true)) {
                $settings[] = $settingsClass;
            }
        }

        foreach (CapellCore::getPackages() as $package) {
            if ($package->setting === null) {
                continue;
            }

            if ($package->setting === '') {
                continue;
            }

            if (in_array($package->setting, $settings, true)) {
                continue;
            }

            $settings[] = $package->setting;
        }

        return array_values(array_filter($settings, is_string(...)));
    }
}
