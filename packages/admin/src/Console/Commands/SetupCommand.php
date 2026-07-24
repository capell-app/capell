<?php

declare(strict_types=1);

namespace Capell\Admin\Console\Commands;

use Capell\Admin\Actions\AdminPanelIntegration\DiscoverFilamentPanelsAction;
use Capell\Admin\Actions\AdminPanelIntegration\IntegrateCapellAdminPanelAction;
use Capell\Admin\Actions\CreateDefaultPagesAction;
use Capell\Admin\Actions\SyncCapellPermissionsAction;
use Capell\Admin\Actions\SyncDashboardFilamentWidgetSettingsAction;
use Capell\Admin\Data\AdminPanelIntegration\AdminPanelCandidateData;
use Capell\Admin\Data\AdminPanelIntegration\AdminPanelChangeResultData;
use Capell\Admin\Data\AdminPanelIntegration\AdminPanelSetupOptionsData;
use Capell\Admin\Data\AdminPanelIntegration\AdminPanelSetupResultData;
use Capell\Admin\Enums\PermissionSyncMode;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Support\AdminRuntimeActivator;
use Capell\Admin\Support\Setup\TailwindSourceRegistrar;
use Capell\Core\Actions\CreateDefaultLanguageAction;
use Capell\Core\Actions\CreateDefaultLanguagesAction;
use Capell\Core\Actions\CreateSiteAction;
use Capell\Core\Actions\CreateThemeAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Console\Commands\Concerns\HasFrontendAssetsOption;
use Capell\Core\Console\Commands\Concerns\PromptsWithOptionFallback;
use Capell\Core\Models\Language;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Creator\BlueprintCreator;
use Capell\Core\Support\Creator\LayoutCreator;
use Capell\Core\Support\Install\FileCacheStoreDirectory;
use Capell\Core\Support\Install\ThemePackageCandidates;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection as SupportCollection;
use InvalidArgumentException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use RuntimeException;

class SetupCommand extends Command
{
    use DescribesCommandOptions;
    use HasFrontendAssetsOption;
    use PromptsWithOptionFallback;

    protected $signature = 'capell:admin-setup
        {--url=}
        {--user=}
        {--languages=}
        {--sites=}
        {--theme= : Theme key to create and assign to the initial site}
        {--assets=*}
        {--skip-shield}
        {--integration-only : Only integrate Capell Admin into a Filament panel}
        {--skip-panel-integration : Do not integrate Capell Admin into Filament}
        {--panel= : Panel provider filename, class name, panel ID, or absolute path}
        {--configurators=auto : Configurator discovery, either auto or comma-separated path=namespace pairs}
        {--no-colors : Do not add Capell panel colors}
        {--no-widgets : Do not add Capell dashboard Filament widgets}
        {--no-navigation : Do not add Capell navigation items and groups}
        {--skip-permission-sync : Skip Capell permission sync when setup is called after admin install}
        {--preview : Show changes without writing files}
        {--force : Skip confirmation prompts}';

    private bool $hasRenderedSetupStep = false;

    public function handle(): int
    {
        resolve(AdminRuntimeActivator::class)->activate();

        $this->writeCommandIntro('set up Capell Admin', $this->adminSetupIntroDetails());

        if (! $this->option('integration-only')) {
            try {
                $this->runContentSetup();
            } catch (InvalidArgumentException $invalidArgumentException) {
                $this->error($invalidArgumentException->getMessage());

                return Command::FAILURE;
            }
        }

        $this->registerTailwindSources();

        if ($this->option('skip-panel-integration')) {
            $this->info('Admin setup complete.');

            return Command::SUCCESS;
        }

        $this->setupStep('discovering Filament panels');
        $discoveredPanels = DiscoverFilamentPanelsAction::run();

        if (! $this->option('integration-only') && $discoveredPanels->isEmpty()) {
            $this->info('Admin setup complete.');

            return Command::SUCCESS;
        }

        if (! $this->option('integration-only') && ! $this->option('force')) {
            $shouldIntegrate = confirm('Integrate Capell Admin into Filament?', default: true);

            if (! $shouldIntegrate) {
                $this->info('Admin setup complete.');

                return Command::SUCCESS;
            }
        }

        $this->setupStep('integrating Capell Admin into Filament');
        $result = IntegrateCapellAdminPanelAction::run($this->resolvePanelSetupOptions());
        $this->renderPanelSetupResult($result);

        if ($result->hasFailures()) {
            return Command::FAILURE;
        }

        $this->info('Admin setup complete.');

        return Command::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function adminSetupIntroDetails(): array
    {
        $details = $this->enabledOptionDetails([
            'url' => 'a provided site URL',
            'user' => 'a provided user',
            'languages' => 'selected languages',
            'sites' => 'selected sites',
            'theme' => 'a selected theme',
            'assets' => 'selected frontend assets',
            'skip-shield' => 'Shield setup skipped',
            'integration-only' => 'Filament integration only',
            'skip-panel-integration' => 'Filament integration skipped',
            'panel' => 'a selected Filament panel',
            'no-colors' => 'panel colors skipped',
            'no-widgets' => 'dashboard Filament widgets skipped',
            'no-navigation' => 'navigation skipped',
            'skip-permission-sync' => 'permission sync skipped',
            'preview' => 'a preview',
            'force' => 'confirmation skipped',
        ]);

        if ($this->optionWasProvided('configurators')) {
            $details[] = 'custom configurator discovery';
        }

        return $details;
    }

    private function runContentSetup(): void
    {
        $this->setupStep('resolving install inputs');
        $siteUrl = $this->resolveSiteUrl();
        $user = $this->resolveUser();
        $selectedLanguages = $this->resolveLanguages();
        $siteOptions = $this->resolveSites();
        $assets = $this->getFrontendAssets();

        $this->setupStep('publishing settings migrations');
        $this->call('capell:publish-migrations', [
            '--type' => 'settings',
            '--items' => CapellAdmin::getSettingMigrations(),
            '--path' => __DIR__ . '/../../../database/settings',
        ]);

        $this->setupStep('running settings migrations');
        $this->call('migrate', [
            '--path' => 'database/settings',
            '--force' => true,
        ]);

        if (! $this->option('skip-shield') && ! $this->option('skip-permission-sync')) {
            $this->setupAuthentication($user);
        }

        $this->setupStep('creating core content types');
        $layoutCreator = resolve(LayoutCreator::class);
        $typeCreator = resolve(BlueprintCreator::class);
        $typeCreator->createSiteType();
        $typeCreator->createPageTypes();
        $typeCreator->createThemeType();

        $this->setupStep('creating languages');
        $languages = $this->createLanguages($selectedLanguages);

        $this->setupStep('creating theme');
        $themeKey = $this->resolveThemeKey();
        $theme = CreateThemeAction::run(
            key: $themeKey,
            name: $this->themeName($themeKey),
            assets: $assets ?? [],
            defaultColors: true,
        );

        $this->setupStep('creating layouts');
        $layoutCreator->setup();

        $this->setupStep('creating sites and default pages');
        $this->setupSites($siteOptions, $siteUrl, $languages, $theme);

        $this->setupStep('syncing dashboard Filament widget settings');
        SyncDashboardFilamentWidgetSettingsAction::run(forceEnableDefaults: true);
    }

    private function setupStep(string $message): void
    {
        if ($this->hasRenderedSetupStep) {
            $this->newLine();
        }

        $this->line('Admin setup: ' . $message);

        $this->hasRenderedSetupStep = true;
    }

    private function resolvePanelSetupOptions(): AdminPanelSetupOptionsData
    {
        $panel = $this->option('panel');

        if (($panel === null || $panel === '') && ! $this->option('force')) {
            $panels = DiscoverFilamentPanelsAction::run();

            if ($panels->count() > 1) {
                $labels = $panels
                    ->mapWithKeys(fn (AdminPanelCandidateData $candidate): array => [$candidate->label() => $candidate->label()])
                    ->all();

                $this->requireInteractiveOrFail('Filament panel', 'Pass --panel=<panel>.');

                $selectedLabel = select(
                    label: 'Which Filament panel should Capell Admin integrate with?',
                    options: array_values($labels),
                );

                $panel = $panels->first(
                    fn (AdminPanelCandidateData $candidate): bool => $candidate->label() === $selectedLabel,
                )?->path;
            }
        }

        return new AdminPanelSetupOptionsData(
            panelPath: is_string($panel) && $panel !== '' ? $panel : null,
            discoverConfigurators: $this->parseConfiguratorOption(),
            addColors: ! $this->option('no-colors'),
            addWidgets: ! $this->option('no-widgets'),
            addNavigation: ! $this->option('no-navigation'),
            createBackup: ! $this->option('preview'),
            preview: $this->option('preview'),
        );
    }

    /**
     * @return array<int, array{in: string, for: string}>
     */
    private function parseConfiguratorOption(): array
    {
        $configuratorOption = $this->option('configurators');

        if (in_array($configuratorOption, [null, '', 'auto'], true)) {
            return [['in' => 'Filament/Configurators', 'for' => 'App\\Filament\\Configurators']];
        }

        throw_unless(is_string($configuratorOption), RuntimeException::class, 'The --configurators option must be auto or comma-separated path=namespace pairs.');

        return collect(explode(',', $configuratorOption))
            ->map(function (string $pair): array {
                throw_unless(str_contains($pair, '='), RuntimeException::class, 'The --configurators option must use path=namespace pairs.');

                [$path, $namespace] = array_map(trim(...), explode('=', $pair, 2));

                throw_if($path === '' || $namespace === '', RuntimeException::class, 'The --configurators option must use non-empty path=namespace pairs.');

                return ['in' => $path, 'for' => $namespace];
            })
            ->values()
            ->all();
    }

    private function renderPanelSetupResult(AdminPanelSetupResultData $result): void
    {
        if ($result->panelPath !== null) {
            $this->line('Filament panel: ' . str($result->panelPath)->after(base_path() . DIRECTORY_SEPARATOR));
        }

        if ($result->backupPath !== null) {
            $this->line('Backup: ' . $result->backupPath);
        }

        $this->table(
            ['Change', 'Status', 'Message'],
            collect($result->changes)
                ->map(fn (AdminPanelChangeResultData $change): array => [
                    $change->change,
                    $change->status->value,
                    $change->message,
                ])
                ->all(),
        );

        if ($this->option('preview')) {
            $this->line('Preview mode: no files were written.');
        }
    }

    private function resolveSiteUrl(): string
    {
        $urlOption = $this->option('url');
        if (filled($urlOption)) {
            return $urlOption;
        }

        $this->requireInteractiveOrFail('Site URL', 'Pass --url=<url>.');

        return text(
            label: 'What is the URL of the site?',
            default: config('app.url'),
            required: true,
            validate: ['siteUrl' => 'required|url'],
        );
    }

    private function resolveUser(): Authenticatable
    {
        $user = $this->option('user');

        $userModel = config('auth.providers.users.model');

        if (is_string($user) && $user !== '') {
            $resolved = str_contains($user, '@')
                ? $userModel::firstWhere('email', $user)
                : $userModel::find($user);

            if ($resolved instanceof Authenticatable) {
                return $resolved;
            }

            if (! $this->input->isInteractive()) {
                throw new RuntimeException(sprintf(
                    "User with identifier '%s' was not found. Pass --user=<email-or-id> or create one with make:filament-user.",
                    $user,
                ));
            }
        }

        $this->requireInteractiveOrFail('Super admin email', 'Pass --user=<email-or-id>.');

        $email = text(
            label: 'What is the email of the super admin user?',
            required: true,
            validate: ['exists:users,email'],
        );

        return $userModel::firstWhere('email', $email);
    }

    /**
     * @return array<int, string>
     */
    private function resolveLanguages(): array
    {
        $defaultLocale = config('app.locale', 'en');

        return $this->parseListOption('languages') ?? [
            is_string($defaultLocale) && $defaultLocale !== '' ? $defaultLocale : 'en',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function resolveSites(): array
    {
        $defaultSiteName = config('app.name', 'Capell Application');

        return $this->parseListOption('sites') ?? [
            is_string($defaultSiteName) && $defaultSiteName !== '' ? $defaultSiteName : 'Capell Application',
        ];
    }

    private function resolveThemeKey(): string
    {
        $theme = $this->option('theme');
        $themeKey = resolve(ThemePackageCandidates::class)->inputThemeKey(
            is_string($theme) && $theme !== '' ? $theme : 'default',
        ) ?? 'default';

        if ($themeKey === 'default') {
            return $themeKey;
        }

        $themeOptions = resolve(ThemePackageCandidates::class)->optionsForInstalledPackages();

        if (! array_key_exists($themeKey, $themeOptions)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown theme [%s]. Installed themes: %s.',
                $themeKey,
                $themeOptions === [] ? 'none' : implode(', ', array_keys($themeOptions)),
            ));
        }

        return $themeKey;
    }

    private function themeName(string $themeKey): string
    {
        if ($themeKey === 'default') {
            return __('capell::generic.default');
        }

        return str($themeKey)->replace(['-', '_'], ' ')->title()->toString();
    }

    /**
     * @return array<int, string>|null
     */
    private function parseListOption(string $optionName): ?array
    {
        $option = $this->option($optionName);

        if (is_string($option)) {
            $values = array_values(array_filter(
                array_map(trim(...), explode(',', $option)),
                static fn (string $value): bool => $value !== '',
            ));

            return $values !== [] ? $values : null;
        }

        if (is_array($option)) {
            $values = array_values(array_filter(
                array_map(
                    static fn (mixed $value): string => trim((string) $value),
                    $option,
                ),
                static fn (string $value): bool => $value !== '',
            ));

            return $values !== [] ? $values : null;
        }

        return null;
    }

    /**
     * @param  array<int, string>|null  $selectedLanguages
     * @return SupportCollection<int, Language>
     */
    private function createLanguages(?array $selectedLanguages): SupportCollection
    {
        return $selectedLanguages !== null && $selectedLanguages !== []
            ? CreateDefaultLanguagesAction::run($selectedLanguages)
            : collect([CreateDefaultLanguageAction::run()]);
    }

    /**
     * @param  array<int, string>  $siteOptions
     * @param  SupportCollection<int, Language>  $languages
     */
    private function setupSites(
        array $siteOptions,
        string $siteUrl,
        SupportCollection $languages,
        Theme $theme,
    ): void {
        $primaryLanguage = $languages->first();

        throw_unless($primaryLanguage instanceof Language, RuntimeException::class, 'At least one language is required to create sites.');

        foreach ($siteOptions as $siteIndex => $siteName) {
            $this->line('Setting up site: ' . $siteName);
            $site = CreateSiteAction::run(
                $siteName,
                url: rtrim($siteUrl, '/') . ($siteIndex > 0 ? '/' . str()->slug($siteName) : ''),
                language: $primaryLanguage,
                languages: $languages,
                theme: $theme,
            );

            $this->line('  Creating default pages');
            CreateDefaultPagesAction::run($site, $site->languages);
        }
    }

    /**
     * Setup authentication for the user.
     */
    private function setupAuthentication(Authenticatable $user): void
    {
        $this->newLine();
        $this->info('Setting up filament shield authentication');
        $this->warn('WARNING: This may take a few moments.');

        // Capell ships its own policies (Capell\Admin\Policies\*); shield must
        // never scaffold stub policies into the host app's app/Policies during
        // install. The --option=permissions flag below already prevents this,
        // but we also force the config flag off so any indirect call to
        // shield:generate that runs without --option still defaults to
        // permissions-only via Utils::getGeneratorOption().
        config()->set('filament-shield.policies.generate', false);

        $fileCacheStoreDirectory = resolve(FileCacheStoreDirectory::class);

        $fileCacheStoreDirectory->retryAfterMissingDirectoryFailure(
            fn (): int => $this->call('shield:super-admin', [
                '--user' => $user->getKey(),
                '--panel' => Filament::getCurrentOrDefaultPanel()?->getId(),
            ]),
        );

        $fileCacheStoreDirectory->retryAfterMissingDirectoryFailure(
            fn (): int => $this->call('shield:generate', [
                '--all' => true,
                '--ignore-existing-policies' => true,
                '--exclude' => [],
                '--option' => 'permissions',
                '--panel' => Filament::getCurrentOrDefaultPanel()?->getId(),
            ]),
        );

        if ($this->option('skip-permission-sync')) {
            return;
        }

        $fileCacheStoreDirectory->retryAfterMissingDirectoryFailure(
            fn (): mixed => SyncCapellPermissionsAction::run(PermissionSyncMode::Install),
        );
    }

    private function registerTailwindSources(): void
    {
        resolve(TailwindSourceRegistrar::class)->register(
            function (string $message): void {
                $this->line($message);
            },
        );
    }
}
