<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Actions\GetPluginsAction;
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
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/** Builds the redacted review from the same resolved input used for execution. */
final class BuildInstallReviewAction
{
    use AsFake;
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
        );
        $packageLabels = [];
        $dependencyLabels = [];
        $selected = [...$input->packages, ...$input->extraPackages];
        foreach ($packages as $package) {
            $label = $package->name . ' (' . ($package->isInstalled()
                ? __('capell-core::install.review.already_installed') : __('capell-core::install.review.downloaded'))
                . ')';
            if (in_array($package->name, $selected, true)) {
                $packageLabels[] = $label;
            } else {
                $dependencyLabels[] = $label;
            }
        }

        $downloads = array_values(array_unique(array_filter($input->extraPackages, fn (string $name): bool => ! $available->has($name))));
        $downloadLabels = [];
        try {
            $catalogue = $downloads === [] ? collect() : GetPluginsAction::run('download');
        } catch (Throwable) {
            $catalogue = collect();
        }

        foreach ($downloads as $name) {
            $package = $catalogue->get($name);
            $downloadLabels[] = $name . ($package instanceof PackageData ? ' [' . ResolveInstallPackageLicenceAction::run($package)->label() . ']' : '');
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
        $toolingEntries = $input->installDeveloperTooling ? [__('capell-core::install.review.tooling_selected')] : [];
        if ($input->configureBoostDeveloperTooling) {
            $tooling .= '; boost:install --guidelines --skills --mcp';
            $toolingEntries[] = 'boost:install --guidelines --skills --mcp';
        }

        $additionalAccounts = array_values(array_map(fn (NewUserData $user): string => $user->email . ' (' . ($user->roleName ?? 'admin') . ')', $input->additionalUsers));

        $items = [
            'summary' => __('capell-core::install.review.summary_counts', ['downloads' => count($downloads), 'packages' => $packages->count()]),
            'site' => $input->siteUrl,
            'database' => $connection . ' / ' . $database . ' — ' . ($input->freshInstall ? __('capell-core::install.review.database_fresh') : __('capell-core::install.review.database_migrate')),
            'packages' => implode(', ', $packageLabels) ?: $none,
            'dependencies' => implode(', ', $dependencyLabels) ?: $none,
            'downloads' => $downloads === [] ? $none : implode(', ', $downloadLabels) . ' — ' . __('capell-core::install.review.composer_dependencies'),
            'theme' => $input->selectedThemeKey ?? $none,
            'content' => implode('; ', $content) ?: $none,
            'administrator' => $this->administrator($input),
            'additional_accounts' => implode('; ', $additionalAccounts) ?: $none,
            'application_changes' => implode('; ', $changes),
            'developer_tooling' => $tooling,
            'after_install' => implode('; ', $after),
            'steps' => implode('; ', array_column(InstallPlan::build($input), 'label')),
        ];
        $labelled = [
            __('capell-core::install.review.summary') => $items['summary'],
            __('capell-core::install.review.site') => $items['site'],
            __('capell-core::install.review.database') => $items['database'],
            __('capell-core::install.review.packages') => $items['packages'],
            __('capell-core::install.review.dependencies') => $items['dependencies'],
            __('capell-core::install.review.downloads') => $items['downloads'],
            __('capell-core::install.review.theme') => $items['theme'],
            __('capell-core::install.review.content') => $items['content'],
            __('capell-core::install.review.administrator') => $items['administrator'],
            __('capell-core::install.review.additional_accounts') => $items['additional_accounts'],
            __('capell-core::install.review.application_changes') => $items['application_changes'],
            __('capell-core::install.review.developer_tooling') => $items['developer_tooling'],
            __('capell-core::install.review.after_install') => $items['after_install'],
            __('capell-core::install.review.steps') => $items['steps'],
        ];

        return new InstallReviewData($labelled, [
            __('capell-core::install.review.dependencies') => $this->entries($dependencyLabels),
            __('capell-core::install.review.downloads') => $this->entries($downloads === []
                ? []
                : [...$downloadLabels, __('capell-core::install.review.composer_dependencies')]),
            __('capell-core::install.review.additional_accounts') => $this->entries($additionalAccounts),
            __('capell-core::install.review.application_changes') => $this->entries($changes),
            __('capell-core::install.review.developer_tooling') => $this->entries($toolingEntries),
            __('capell-core::install.review.after_install') => $this->entries($after),
        ]);
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

            return $existing
                ? __('capell-core::install.review.update_account', ['email' => $input->newUser->email, 'name' => $input->newUser->name])
                : __('capell-core::install.review.create_account', ['email' => $input->newUser->email, 'name' => $input->newUser->name]);
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

    /**
     * Translation lookups are typed `string|array`; a review entry is only ever text.
     *
     * @param  array<int|string, mixed>  $entries
     * @return list<string>
     */
    private function entries(array $entries): array
    {
        return array_values(array_filter($entries, is_string(...)));
    }
}
