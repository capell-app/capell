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
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Core\Actions\CreateDefaultLanguageAction;
use Capell\Core\Actions\CreateDefaultLanguagesAction;
use Capell\Core\Actions\CreateSiteAction;
use Capell\Core\Actions\CreateThemeAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Console\Commands\Concerns\HasFrontendAssetsOption;
use Capell\Core\Console\Commands\Concerns\PromptsWithOptionFallback;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Theme;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\Creator\BlueprintCreator;
use Capell\Core\Support\Creator\LayoutCreator;
use Capell\Core\Support\Install\FileCacheStoreDirectory;
use Capell\Core\Support\Install\ThemePackageCandidates;
use Composer\InstalledVersions;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use OutOfBoundsException;
use ReflectionClass;
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
        $themeCss = resource_path('css/filament/admin/theme.css');

        if (! File::exists($themeCss)) {
            return;
        }

        $contents = $this->ensureTailwindFourThemeCssCompatibility($themeCss, File::get($themeCss));
        $sources = [
            ...$this->installedPackageTailwindSources($themeCss),
            ...$this->firstPartyProviderTailwindSources($themeCss),
        ];
        $sources[] = "@source '../../../../storage/capell/tailwind-classes.txt';";

        $missingSources = array_values(array_filter(
            array_unique($sources),
            fn (string $source): bool => ! str_contains($contents, $source),
        ));

        if ($missingSources === []) {
            return;
        }

        File::append($themeCss, PHP_EOL . implode(PHP_EOL, $missingSources) . PHP_EOL);

        $this->line('Added Capell Tailwind sources to theme.css');
    }

    private function ensureTailwindFourThemeCssCompatibility(string $themeCss, string $contents): string
    {
        $updatedContents = $contents;

        if (! str_contains($updatedContents, "@import 'tailwindcss';")
            && ! str_contains($updatedContents, '@import "tailwindcss";')
            && str_contains($updatedContents, '@tailwind base;')
        ) {
            $updatedContents = preg_replace(
                '/(?:@tailwind\s+(?:base|components|utilities|variants);\s*)+/m',
                "@import 'tailwindcss';\n",
                $updatedContents,
                1,
            ) ?? $updatedContents;
        }

        $updatedContents = str_replace(
            ["@config 'tailwind.config.js';", '@config "tailwind.config.js";'],
            ["@config './tailwind.config.js';", '@config "./tailwind.config.js";'],
            $updatedContents,
        );

        if ($updatedContents === $contents) {
            return $contents;
        }

        File::put($themeCss, $updatedContents);
        $this->line('Updated theme.css for Tailwind 4 compatibility');

        return $updatedContents;
    }

    /**
     * @return array<int, string>
     */
    private function installedPackageTailwindSources(string $themeCss): array
    {
        return collect($this->installedCapellPackagePaths())
            ->sortKeys()
            ->values()
            ->map(fn (string $packagePath): ?string => $this->tailwindSourceForPackagePath($packagePath, $themeCss))
            ->filter()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function installedCapellPackagePaths(): array
    {
        $packages = [];

        foreach (InstalledVersions::getInstalledPackages() as $packageName) {
            if (! str_starts_with($packageName, 'capell-app/')) {
                continue;
            }

            $installPath = InstalledVersions::getInstallPath($packageName);

            if ($installPath !== null) {
                $packages[$packageName] = rtrim($installPath, DIRECTORY_SEPARATOR);
            }
        }

        return [
            ...$packages,
            ...$this->pathRepositoryCapellPackagePaths(),
            ...$this->monorepoCapellPackagePaths(),
            ...$this->registeredCapellPackagePaths(),
            ...$this->firstPartyProviderCapellPackagePaths(),
            ...$this->sourceTreeCapellPackagePaths(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function registeredCapellPackagePaths(): array
    {
        return CapellCore::getPackages(withoutCore: false)
            ->filter(
                fn (PackageData $package): bool => str_starts_with($package->name, 'capell-app/')
                    && is_string($package->path)
                    && $package->path !== '',
            )
            ->mapWithKeys(
                fn (PackageData $package): array => [
                    $package->name => rtrim((string) $package->path, DIRECTORY_SEPARATOR),
                ],
            )
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function firstPartyProviderCapellPackagePaths(): array
    {
        /** @var list<class-string> $providerClasses */
        $providerClasses = [
            AdminServiceProvider::class,
            CapellServiceProvider::class,
        ];

        return collect($providerClasses)
            ->mapWithKeys(function (string $serviceProviderClass): array {
                $packagePath = $this->packagePathFromClass($serviceProviderClass);

                if ($packagePath === null) {
                    return [];
                }

                $composerJson = $this->readComposerJson($packagePath . DIRECTORY_SEPARATOR . 'composer.json');
                $packageName = (string) ($composerJson['name'] ?? '');

                if (! str_starts_with($packageName, 'capell-app/')) {
                    return [];
                }

                return [$packageName => $packagePath];
            })
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function firstPartyProviderTailwindSources(string $themeCss): array
    {
        /** @var list<class-string> $providerClasses */
        $providerClasses = [
            AdminServiceProvider::class,
            CapellServiceProvider::class,
        ];

        return collect($providerClasses)
            ->map(fn (string $serviceProviderClass): ?string => $this->packagePathFromClass($serviceProviderClass))
            ->filter()
            ->map(fn (string $packagePath): ?string => $this->tailwindSourceForPackagePath($packagePath, $themeCss, requireViews: false))
            ->filter(fn (?string $source): bool => $source !== null)
            ->values()
            ->all();
    }

    /**
     * @param  class-string  $class
     */
    private function packagePathFromClass(string $class): ?string
    {
        $fileName = new ReflectionClass($class)->getFileName();

        if (! is_string($fileName)) {
            return null;
        }

        $path = dirname($fileName);

        while (dirname($path) !== $path) {
            if (File::exists($path . DIRECTORY_SEPARATOR . 'composer.json')) {
                return rtrim($path, DIRECTORY_SEPARATOR);
            }

            $path = dirname($path);
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function sourceTreeCapellPackagePaths(): array
    {
        return collect($this->sourceTreePackageRootCandidates())
            ->flatMap(fn (string $packagesPath): array => collect(['admin', 'core', 'frontend'])
                ->mapWithKeys(function (string $packageDirectory) use ($packagesPath): array {
                    $packagePath = $packagesPath . DIRECTORY_SEPARATOR . $packageDirectory;
                    $composerJson = $this->readComposerJson($packagePath . DIRECTORY_SEPARATOR . 'composer.json');
                    $packageName = (string) ($composerJson['name'] ?? '');

                    if (! str_starts_with($packageName, 'capell-app/')) {
                        return [];
                    }

                    return [$packageName => rtrim($packagePath, DIRECTORY_SEPARATOR)];
                })
                ->all())
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function sourceTreePackageRootCandidates(): array
    {
        $paths = [];
        $path = __DIR__;

        while (dirname($path) !== $path) {
            $packageRoot = $path . DIRECTORY_SEPARATOR . 'packages';

            if (File::isDirectory($packageRoot)) {
                $realPackageRoot = realpath($packageRoot);
                $paths[] = $realPackageRoot !== false ? $realPackageRoot : $packageRoot;
            }

            if (File::isDirectory($path . DIRECTORY_SEPARATOR . 'admin')
                && File::isDirectory($path . DIRECTORY_SEPARATOR . 'core')
            ) {
                $realPath = realpath($path);
                $paths[] = $realPath !== false ? $realPath : $path;
            }

            $path = dirname($path);
        }

        return array_values(array_unique($paths));
    }

    private function tailwindSourceForPackagePath(string $packagePath, string $themeCss, bool $requireViews = true): ?string
    {
        $viewsPath = $packagePath . '/resources/views';

        if ($requireViews && ! File::isDirectory($viewsPath)) {
            return null;
        }

        $relativePath = $this->relativePath(dirname($themeCss), $viewsPath);

        return sprintf("@source '%s/**/*.blade.php';", $relativePath);
    }

    /**
     * @return array<string, string>
     */
    private function pathRepositoryCapellPackagePaths(): array
    {
        $rootPath = $this->composerRootPath();

        if ($rootPath === null) {
            return [];
        }

        $composerJson = $this->readComposerJson($rootPath . '/composer.json');
        $repositories = is_array($composerJson['repositories'] ?? null) ? $composerJson['repositories'] : [];
        $requiredPackageNames = array_keys(array_merge(
            is_array($composerJson['require'] ?? null) ? $composerJson['require'] : [],
            is_array($composerJson['require-dev'] ?? null) ? $composerJson['require-dev'] : [],
        ));

        $packages = [];

        foreach ($repositories as $repository) {
            if (! is_array($repository)) {
                continue;
            }

            if (($repository['type'] ?? null) !== 'path') {
                continue;
            }

            $url = (string) ($repository['url'] ?? '');
            $url = str_starts_with($url, './') ? substr($url, 2) : $url;
            $pattern = str_starts_with($url, '/') ? $url : $rootPath . '/' . $url;

            $directories = glob($pattern, GLOB_ONLYDIR);

            foreach ($directories !== false ? $directories : [] as $directory) {
                $packageComposerJson = $this->readComposerJson(rtrim($directory, DIRECTORY_SEPARATOR) . '/composer.json');
                $packageName = (string) ($packageComposerJson['name'] ?? '');

                if (! str_starts_with($packageName, 'capell-app/')) {
                    continue;
                }

                if (! in_array($packageName, $requiredPackageNames, true)) {
                    continue;
                }

                $packages[$packageName] = rtrim($directory, DIRECTORY_SEPARATOR);
            }
        }

        return $packages;
    }

    /**
     * @return array<string, string>
     */
    private function monorepoCapellPackagePaths(): array
    {
        $rootPath = $this->composerRootPath();

        if ($rootPath === null) {
            return [];
        }

        $this->readComposerJson($rootPath . '/composer.json');
        $manifestPaths = glob($rootPath . '/packages/*/capell.json');
        $packages = [];

        foreach ($manifestPaths !== false ? $manifestPaths : [] as $manifestPath) {
            $packagePath = dirname($manifestPath);
            $packageComposerJson = $this->readComposerJson($packagePath . '/composer.json');
            $packageName = (string) ($packageComposerJson['name'] ?? '');

            if (! str_starts_with($packageName, 'capell-app/')) {
                continue;
            }

            $packages[$packageName] = rtrim($packagePath, DIRECTORY_SEPARATOR);
        }

        return $packages;
    }

    private function composerRootPath(): ?string
    {
        try {
            $rootPath = InstalledVersions::getInstallPath(InstalledVersions::getRootPackage()['name']);
        } catch (OutOfBoundsException) {
            $rootPath = null;
        }

        if ($rootPath === null) {
            return null;
        }

        $rootPath = realpath($rootPath);

        return $rootPath !== false ? $rootPath : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readComposerJson(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $contents = json_decode(File::get($path), associative: true);

        return is_array($contents) ? $contents : [];
    }

    private function relativePath(string $from, string $to): string
    {
        $fromParts = explode('/', trim($this->normalisePath($from), '/'));
        $toParts = explode('/', trim($this->normalisePath($to), '/'));

        while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        return implode('/', [
            ...array_fill(0, count($fromParts), '..'),
            ...$toParts,
        ]);
    }

    private function normalisePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }
}
