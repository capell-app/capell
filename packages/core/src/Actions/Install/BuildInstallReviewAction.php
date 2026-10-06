<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Data\Install\InstallReviewData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\NewUserData;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\Cli\FilamentAdminInstallPreflight;
use Capell\Core\Support\Install\Cli\InstallCacheOptionCatalog;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Install\PackageWorkflowPlanner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsObject;

/** Builds the redacted review from the same resolved input used for execution. */
final class BuildInstallReviewAction
{
    use AsObject;

    /**
     * @param  array<string>  $cachesToClear
     * @param  array<string>  $patchLabels
     */
    public function handle(InstallInputData $input, bool $runNpmBuild = false, bool $removeInstaller = false, array $cachesToClear = [], array $patchLabels = []): InstallReviewData
    {
        $none = __('capell-core::install.review.none');
        $available = CapellCore::getPackages();
        $packages = resolve(PackageWorkflowPlanner::class)->expandAndOrder(
            $available,
            array_values(array_unique([...$input->packages, ...$input->extraPackages])),
            $input->freshInstall,
        )->reject(fn (PackageData $package): bool => in_array($package->name, $input->extraPackages, true));
        $packageLabels = [];
        foreach ($packages as $package) {
            $packageLabels[] = $package->name . ' (' . ($package->isInstalled()
                ? __('capell-core::install.review.already_installed') : __('capell-core::install.review.downloaded'))
                . ($packages->contains(fn (PackageData $other): bool => in_array($package->name, $other->getRequirements(), true)) ? '; ' . __('capell-core::install.review.dependency') : '') . ')';
        }

        $connection = (string) config('database.default');
        $database = (string) config('database.connections.' . $connection . '.database');
        $content = [];
        if ($input->seedDefaultData) {
            $content[] = __('capell-core::install.review.default_content');
        }

        if ($input->demoContent) {
            $content[] = __('capell-core::install.review.demo_content', ['sites' => implode(', ', $input->demoSites ?? []), 'languages' => implode(', ', $input->demoLanguages ?? $input->languages)]);
        }

        if ($input->seedDatabase) {
            $content[] = __('capell-core::install.review.application_seeder');
        }

        $changes = [__('capell-core::install.review.environment')];
        $hasAdmin = in_array('capell-app/admin', [...$input->packages, ...$input->extraPackages], true);
        if ($hasAdmin && ! resolve(FilamentAdminInstallPreflight::class)->hasInstalledPanelProvider()) {
            $changes[] = __('capell-core::install.review.filament_panel');
        }

        if ($hasAdmin && $input->integrateAdminPanel) {
            $changes[] = __('capell-core::install.review.admin_integration');
        }

        if ($hasAdmin && $input->adminAddColors) {
            $changes[] = __('capell-core::install.review.admin_colors');
        }

        if ($hasAdmin && $input->adminAddWidgets) {
            $changes[] = __('capell-core::install.review.admin_widgets');
        }

        if ($hasAdmin && $input->adminAddNavigation) {
            $changes[] = __('capell-core::install.review.admin_navigation');
        }

        $changes[] = $input->installWelcomeRoute ? __('capell-core::install.review.homepage_capell') : __('capell-core::install.review.homepage_existing');
        array_push($changes, ...$patchLabels);
        $after = [__('capell-core::install.review.package_cache')];
        $cacheOptions = [...InstallCacheOptionCatalog::baseOptions(), ...array_map(fn (array $option): string => $option['label'], InstallCacheOptionCatalog::optionalOptions())];
        foreach ($cachesToClear as $cache) {
            $after[] = $cacheOptions[$cache] ?? $cache;
        }

        if ($runNpmBuild || $input->rebuildResources) {
            $after[] = __('capell-core::install.review.build_assets');
        }

        if ($input->generateSitemap) {
            $after[] = __('capell-core::install.review.sitemap');
        }

        if ($removeInstaller) {
            $after[] = __('capell-core::install.review.remove_installer');
        }

        $tooling = $input->installDeveloperTooling ? __('capell-core::install.review.tooling_selected') : $none;
        if ($input->configureBoostDeveloperTooling) {
            $tooling .= '; boost:install --guidelines --skills --mcp';
        }

        $items = [
            'site' => $input->siteUrl,
            'database' => $connection . ' / ' . $database . ' — ' . ($input->freshInstall ? __('capell-core::install.review.database_fresh') : __('capell-core::install.review.database_migrate')),
            'packages' => implode(', ', $packageLabels) ?: $none,
            'downloads' => $input->extraPackages === [] ? $none : implode(', ', $input->extraPackages) . ' — ' . __('capell-core::install.review.composer_dependencies'),
            'theme' => $input->selectedThemeKey ?? $none,
            'content' => implode('; ', $content) ?: $none,
            'administrator' => $this->administrator($input),
            'additional_accounts' => implode('; ', array_map(fn (NewUserData $user): string => $user->email . ' (' . ($user->roleName ?? 'admin') . ')', $input->additionalUsers)) ?: $none,
            'application_changes' => implode('; ', $changes),
            'developer_tooling' => $tooling,
            'after_install' => implode('; ', $after),
            'steps' => implode('; ', array_column(InstallPlan::build($input), 'label')),
        ];
        $labelled = [];
        foreach ($items as $key => $value) {
            $labelled[__('capell-core::install.review.' . $key)] = $value;
        }

        return new InstallReviewData($labelled);
    }

    private function administrator(InstallInputData $input): string
    {
        if ($input->newUser instanceof NewUserData) {
            $existing = false;
            if (! $input->freshInstall) {
                /** @var class-string<Model> $model */
                $model = config('auth.providers.users.model');
                $instance = new $model;
                if (Schema::connection($instance->getConnectionName())->hasTable($instance->getTable())) {
                    $existing = $model::query()->where('email', $input->newUser->email)->exists();
                }
            }

            return __('capell-core::install.review.' . ($existing ? 'update_account' : 'create_account'), ['email' => $input->newUser->email, 'name' => $input->newUser->name]);
        }

        if ($input->userId !== null) {
            /** @var class-string<Model> $model */
            $model = config('auth.providers.users.model');
            $user = $model::query()->find($input->userId);

            return __('capell-core::install.review.use_account', [
                'id' => $input->userId, 'email' => (string) $user?->getAttribute('email'), 'name' => (string) $user?->getAttribute('name'),
            ]);
        }

        return __('capell-core::install.review.account_unspecified');
    }
}
