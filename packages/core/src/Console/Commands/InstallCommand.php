<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Actions\Install\BuildAndAnnounceInstallSpecAction;
use Capell\Core\Actions\Install\BuildInstallHandoffAction;
use Capell\Core\Actions\Install\OrchestrateInstallAction;
use Capell\Core\Actions\Install\PrepareInstallApplicationAction;
use Capell\Core\Actions\Install\WriteInstallHandoffAction;
use Capell\Core\Actions\RemovePackageAction;
use Capell\Core\Actions\RunNpmBuildAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Console\Commands\Concerns\HasPackageSelection;
use Capell\Core\Console\Commands\Concerns\PromptsWithOptionFallback;
use Capell\Core\Contracts\InstallOrchestrationHost;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\Install\InstallOrchestrationData;
use Capell\Core\Data\Install\InstallProfileData;
use Capell\Core\Data\Install\InstallRunResultData;
use Capell\Core\Data\Install\InstallStepData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Install\Cli\FilamentAdminInstallPreflight;
use Capell\Core\Support\Install\Cli\FreshInstallDefaults;
use Capell\Core\Support\Install\Cli\InstallCacheOptionResolver;
use Capell\Core\Support\Install\Cli\InstallCommandPresenter;
use Capell\Core\Support\Install\Cli\InstallPackageSetComposer;
use Capell\Core\Support\Install\Cli\InstallPostInstallOptionResolver;
use Capell\Core\Support\Install\Cli\InstallUserPrompter;
use Capell\Core\Support\Install\ConsoleProgressReporter;
use Capell\Core\Support\Install\DeveloperToolingInstallationState;
use Capell\Core\Support\Install\InstallInputFactory;
use Capell\Core\Support\Install\InstallPatchConfirmation;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Install\InstallProfileRepository;
use Capell\Core\Support\Install\ThemePackageCandidates;
use Capell\Core\Support\Install\WelcomeRouteInstaller;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

use RuntimeException;
use Spatie\LaravelPackageTools\Commands\Concerns\AskToStarRepoOnGitHub;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Throwable;

class InstallCommand extends Command implements InstallOrchestrationHost
{
    use AskToStarRepoOnGitHub;
    use DescribesCommandOptions;
    use HasPackageSelection;
    use PromptsWithOptionFallback;

    private const int MinimumMemoryLimitBytes = 536_870_912;

    private const string INSTALL_PERMISSIONS_DOC_URL = 'https://docs.capell.app/getting-started/install/#install-time-write-permissions';

    /** @var string */
    protected $signature = 'capell:install
        {--demo}
        {--plan : Print the exact install plan and exit without mutation}
        {--fresh= : Refresh the database before installing; pass force to skip the confirmation}
        {--profile= : Install profile key from config/capell-install-profiles.php or capell-install-profiles.json}
        {--package-mode= : Package selection mode: core, all, or custom}
        {--packages= : Comma-separated package names (defaults to all installable)}
        {--all-packages : Install all composer-installed Capell packages}
        {--theme= : Theme key to activate when multiple themes are available}
        {--remove-installer : Remove capell-app/installer after a successful install}
        {--languages= : Comma-separated demo language codes}
        {--sites= : Comma-separated demo site names}
        {--seed : Run the application database seeder after installing}
        {--no-seed-default-data : Skip default site, language, content type, and page setup}
        {--spec= : Path to a site spec requiring site, theme.key, and at least one page}
        {--url= : Site URL (defaults to APP_URL)}
        {--user= : User email or ID used as the default author for generated content}
        {--name= : Name for the first user created during install}
        {--email= : Email for the first user created during install}
        {--password= : Password for the first user created during install}
        {--role-users : Create example users for common admin roles}
        {--role-user-password= : Password for example role users}
        {--no-side-effects : Skip all real sub-commands and side effects}
        {--clear-cache : Automatically clear caches without prompt}
        {--generate-sitemap : Generate sitemaps}
        {--install-welcome-route : Remove Laravel default welcome home route when present}
        {--developer-tooling : Install Laravel Boost and Capell Agent Bridge developer tooling}
        {--no-boost-install : Install developer tooling packages without running boost:install}
        {--handoff-json= : Write the redacted install handoff to a JSON file}
        {--production : Run in unattended production-safe mode: forces --no-interaction and refuses --fresh}
    ';

    /** @var string */
    protected $description = 'Install Capell and optionally add demo content.';

    /** @var array<int, string> */
    private array $manualInstallChanges = [];

    private ?InstallProfileData $installProfile = null;

    private bool $orchestratedSeedDefaultData = true;

    public function handle(): int
    {
        $bootExitCode = $this->bootInstallCommand();
        if ($bootExitCode !== null) {
            return $bootExitCode;
        }

        $planOnly = $this->option('plan');
        $noSideEffects = $this->option('no-side-effects');
        [$freshInstall, $forceFreshInstall] = $this->freshInstallOptions();
        $demo = $this->shouldInstallDemoContent();
        $userEmailOption = $this->option('user');
        $userPrompter = $this->userPrompter();
        $newUser = $userPrompter->newUserFromOptions(
            $this->option('name'),
            $this->option('email'),
            $this->option('password'),
        );
        $clearCache = $this->option('clear-cache');
        $generateSitemap = $this->option('generate-sitemap');
        $seedDatabase = (bool) $this->option('seed');
        $seedDefaultData = ! $this->option('no-seed-default-data');
        $freshInstallConfirmed = false;

        $this->writeCommandIntro(
            'install Capell',
            resolve(InstallCommandPresenter::class)->introDetails(
                $freshInstall,
                $forceFreshInstall,
                $demo,
                $planOnly,
                $noSideEffects,
            ),
        );

        if ($noSideEffects && ! $planOnly) {
            $this->info('Skipping all side effects (--no-side-effects).');

            return CommandAlias::SUCCESS;
        }

        if ($freshInstall
            && ! $planOnly
            && ! $this->confirmFreshInstall($forceFreshInstall)
        ) {
            $this->logInstallDebug('fresh install cancelled');

            return CommandAlias::FAILURE;
        }

        $freshInstallConfirmed = $freshInstall && ! $planOnly;

        $this->logInstallDebug('resolved demo option', [
            'demo' => $demo,
        ]);

        if ($this->isInstalled()
            && ! $freshInstall
            && confirm('Capell is already installed. Refresh the database and reinstall?', false)
        ) {
            $freshInstall = true;
            $this->logInstallDebug('existing install converted to fresh install');
        }

        if (! $freshInstallConfirmed
            && $freshInstall
            && ! $planOnly
            && ! $this->confirmFreshInstall($forceFreshInstall)
        ) {
            $this->logInstallDebug('fresh install cancelled after installed check');

            return CommandAlias::FAILURE;
        }

        $siteUrl = $this->resolveSiteUrl();
        $packages = $this->resolveSelectedPackages($demo, $freshInstall);
        if (! $packages instanceof Collection) {
            return CommandAlias::FAILURE;
        }

        $reporter = new ConsoleProgressReporter($this);

        if (! $planOnly && ! resolve(FilamentAdminInstallPreflight::class)->ensureReady(
            packages: $packages,
            interactive: $this->input->isInteractive(),
            useFreshDemoDefaults: $this->shouldUseFreshDemoDefaults(),
            reporter: $reporter,
            writeError: function (string $message): void {
                $this->error($message);
            },
        )) {
            $this->logInstallDebug('filament admin panel check failed');

            return CommandAlias::FAILURE;
        }

        $this->logInstallDebug('resolving theme selection');
        [$selectedThemeKey, $themeExitCode] = $this->resolveThemeSelection();
        if ($themeExitCode !== null) {
            $this->logInstallDebug('theme selection failed', [
                'exit_code' => $themeExitCode,
            ]);

            return $themeExitCode;
        }

        [$packages, $themeExtraPackages] = $this->includeSelectedThemePackage($packages, $selectedThemeKey, $freshInstall);
        $installTimePackageNames = $this->installTimePackageNamesFromSelection();

        if ($packages->isEmpty() && $installTimePackageNames === [] && $themeExtraPackages === []) {
            $this->warn('No packages selected.');
        }

        $this->logInstallDebug('resolved theme selection', [
            'selected_theme_key' => $selectedThemeKey,
            'extra_packages' => array_values(array_unique([...$installTimePackageNames, ...$themeExtraPackages])),
            'packages' => $packages->keys()->values()->all(),
        ]);

        $languages = $this->resolveLanguages();
        $siteOptions = $this->resolveSites();
        $this->logInstallDebug('resolved languages and sites', [
            'languages' => $languages,
            'sites' => $siteOptions,
        ]);

        $hasFrontend = $packages->filter(fn (PackageData $package): bool => $package->hasFrontendScope())->isNotEmpty();
        $installWelcomeRoute = $planOnly
            ? $hasFrontend && $this->option('install-welcome-route')
            : resolve(InstallPostInstallOptionResolver::class)->resolveWelcomeRoute(
                hasFrontend: $hasFrontend,
                installWelcomeRouteOption: (bool) $this->option('install-welcome-route'),
                interactive: $this->input->isInteractive(),
                welcomeRouteInstaller: resolve(WelcomeRouteInstaller::class),
                recordManualInstallChange: function (string $message): void {
                    $this->recordManualInstallChange($message);
                },
                writeWarning: function (string $message): void {
                    $this->warn($message);
                },
            );
        $this->logInstallDebug('resolved welcome route option', [
            'has_frontend' => $hasFrontend,
            'install_welcome_route' => $installWelcomeRoute,
        ]);

        if ($planOnly) {
            $this->logInstallDebug('building plan-only input');
            $developerToolingChoice = resolve(InstallPostInstallOptionResolver::class)->resolveDeveloperToolingChoiceForPlan(
                developerToolingRequested: (bool) $this->option('developer-tooling'),
                skipBoostInstall: (bool) $this->option('no-boost-install'),
                developerToolingInstalled: resolve(DeveloperToolingInstallationState::class)->isInstalled(),
            );

            $inputData = resolve(InstallInputFactory::class)->fromResolvedConsoleInput(
                siteUrl: $siteUrl,
                packages: $packages->keys()->all(),
                languages: $languages,
                demoContent: $demo,
                cachesToClear: [],
                generateSitemap: $generateSitemap,
                generateStaticSite: false,
                demoSites: $demo ? $siteOptions : null,
                demoLanguages: $demo ? $languages : null,
                newUser: $newUser,
                seedDefaultData: $seedDefaultData,
                seedDatabase: $seedDatabase,
                freshInstall: $freshInstall,
                installWelcomeRoute: $installWelcomeRoute,
                installDeveloperTooling: $developerToolingChoice->installDeveloperTooling,
                configureBoostDeveloperTooling: $developerToolingChoice->configureBoostDeveloperTooling,
                selectedThemeKey: resolve(ThemePackageCandidates::class)->inputThemeKey($selectedThemeKey),
                extraPackages: array_values(array_unique([...$installTimePackageNames, ...$themeExtraPackages])),
            );

            $this->outputPlan($inputData);
            $this->logInstallDebug('finished plan-only command');

            return CommandAlias::SUCCESS;
        }

        $this->logInstallDebug('resolving admin user');
        [$userId, $resolvedNewUser, $exitCode] = $userPrompter->resolveUserInput(
            $userEmailOption,
            $newUser,
            $freshInstall,
            $this->shouldUseFreshDemoDefaults(),
        );
        if ($exitCode !== null) {
            $this->logInstallDebug('admin user resolution failed', [
                'exit_code' => $exitCode,
            ]);

            return $exitCode;
        }

        $this->logInstallDebug('resolved admin user', [
            'user_id' => $userId,
            'new_user_email' => $resolvedNewUser?->email,
        ]);

        [$additionalUsers, $additionalUsersExitCode] = $userPrompter->resolveAdditionalUsersInput(
            (bool) $this->option('role-users'),
            $this->option('role-user-password'),
            resolve(InstallInputFactory::class),
        );
        if ($additionalUsersExitCode !== null) {
            $this->logInstallDebug('additional user resolution failed', [
                'exit_code' => $additionalUsersExitCode,
            ]);

            return $additionalUsersExitCode;
        }

        $this->logInstallDebug('resolved additional users', [
            'count' => count($additionalUsers),
        ]);

        $developerToolingChoice = resolve(InstallPostInstallOptionResolver::class)->resolveDeveloperToolingChoice(
            developerToolingRequested: (bool) $this->option('developer-tooling'),
            skipBoostInstall: (bool) $this->option('no-boost-install'),
            developerToolingInstalled: resolve(DeveloperToolingInstallationState::class)->isInstalled(),
            interactive: $this->input->isInteractive(),
            useFreshDemoDefaults: $this->shouldUseFreshDemoDefaults(),
        );
        $this->logInstallDebug('resolved developer tooling', [
            'install_developer_tooling' => $developerToolingChoice->installDeveloperTooling,
            'configure_boost_developer_tooling' => $developerToolingChoice->configureBoostDeveloperTooling,
        ]);

        $runNpmBuild = resolve(InstallPostInstallOptionResolver::class)->shouldRunNpmBuild(
            hasFrontend: $hasFrontend,
            interactive: $this->input->isInteractive(),
            useFreshDemoDefaults: $this->shouldUseFreshDemoDefaults(),
        );
        $removeInstallerPackage = resolve(InstallPostInstallOptionResolver::class)->shouldRemoveInstallerPackage(
            installerPackageInstalled: CapellCore::hasPackage($this->installerPackageName()),
            removeInstallerOption: (bool) $this->option('remove-installer'),
            interactive: $this->input->isInteractive(),
            useFreshDemoDefaults: $this->shouldUseFreshDemoDefaults(),
        );
        $this->logInstallDebug('resolved post-install side effects', [
            'run_npm_build' => $runNpmBuild,
            'remove_installer_package' => $removeInstallerPackage,
        ]);

        $inputData = resolve(InstallInputFactory::class)->fromResolvedConsoleInput(
            siteUrl: $siteUrl,
            packages: $packages->keys()->all(),
            languages: $languages,
            demoContent: $demo,
            cachesToClear: [],
            generateSitemap: $generateSitemap,
            generateStaticSite: false,
            demoSites: $demo ? $siteOptions : null,
            demoLanguages: $demo ? $languages : null,
            userId: $userId,
            newUser: $resolvedNewUser,
            seedDefaultData: $seedDefaultData,
            seedDatabase: $seedDatabase,
            freshInstall: $freshInstall,
            installWelcomeRoute: $installWelcomeRoute,
            installDeveloperTooling: $developerToolingChoice->installDeveloperTooling,
            configureBoostDeveloperTooling: $developerToolingChoice->configureBoostDeveloperTooling,
            additionalUsers: $additionalUsers,
            selectedThemeKey: resolve(ThemePackageCandidates::class)->inputThemeKey($selectedThemeKey),
            extraPackages: array_values(array_unique([...$installTimePackageNames, ...$themeExtraPackages])),
        );

        $this->orchestratedSeedDefaultData = $seedDefaultData;
        try {
            $this->logInstallDebug('running install orchestration');
            OrchestrateInstallAction::run(
                $inputData,
                new InstallOrchestrationData(
                    outputPlan: ! $this->input->isInteractive(),
                    runNpmBuild: $runNpmBuild,
                    removeInstaller: $removeInstallerPackage,
                    cachesToClear: resolve(InstallCacheOptionResolver::class)->resolve(
                        $clearCache,
                        $freshInstall,
                        fn (string $command): bool => $this->getApplication()?->has($command) === true,
                    ),
                ),
                $reporter,
                $this,
            );
            $this->logInstallDebug('install orchestration finished');
        } catch (Throwable $throwable) {
            report($throwable);
            resolve(InstallCommandPresenter::class)->renderFailure($throwable, $this->getOutput());
            $this->logInstallDebug('install action failed', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return CommandAlias::FAILURE;
        }

        $this->logInstallDebug('finished command');

        return CommandAlias::SUCCESS;
    }

    public function outputPlan(InstallInputData $inputData): void
    {
        $steps = InstallPlan::steps($inputData);

        $this->newLine();
        $this->line('<fg=blue;options=bold>Capell Install Plan</>');
        $this->newLine();

        $steps->each(function (InstallStepData $step, int $index): void {
            $this->line(sprintf('%d. %s', $index + 1, $step->label));
        });

        $this->newLine();
    }

    public function buildFrontendAssets(): void
    {
        $this->line('Running: npm run build');

        try {
            RunNpmBuildAction::run();
            $this->info('Production build completed successfully.');
        } catch (RuntimeException $runtimeException) {
            $this->error('npm build failed.');
            $this->line($runtimeException->getMessage());

            throw $runtimeException;
        }
    }

    public function removeInstaller(): void
    {
        try {
            RemovePackageAction::run($this->installerPackageName());
            $this->info('Capell installer package removed successfully.');
        } catch (RuntimeException $runtimeException) {
            $this->error('Unable to remove the Capell installer package.');
            $this->line($runtimeException->getMessage());
        }
    }

    public function prepareApplication(InstallInputData $inputData, ProgressReporter $reporter): void
    {
        PrepareInstallApplicationAction::run(
            inputData: $inputData,
            hasFilamentAdminPanelProvider: resolve(FilamentAdminInstallPreflight::class)->hasInstalledPanelProvider(),
            interactive: $this->input->isInteractive(),
            useFreshDemoDefaults: $this->shouldUseFreshDemoDefaults(),
            reporter: $reporter,
            confirmPatch: fn (InstallPatchConfirmation $confirmation): bool => confirm(
                label: $confirmation->label,
                default: $confirmation->default,
                hint: $confirmation->hint ?? '',
            ),
            recordManualInstallChange: function (string $message): void {
                $this->recordManualInstallChange($message);
            },
        );
    }

    public function reportManualChanges(): void
    {
        $changes = array_values(array_unique($this->manualInstallChanges));

        if ($changes === []) {
            return;
        }

        $this->newLine();
        $this->warn('Manual install changes required');

        foreach ($changes as $change) {
            $this->line('- ' . $change);
        }

        $this->line('Review the install-time write permissions and manual patch list: ' . self::INSTALL_PERMISSIONS_DOC_URL);
    }

    public function upgradeFilament(): void
    {
        if (! $this->getApplication()?->has('filament:upgrade')) {
            return;
        }

        $this->logInstallDebug('running filament upgrade');
        $this->callSilent('filament:upgrade');
        $this->logInstallDebug('filament upgrade finished');
    }

    public function finalizeInstall(InstallInputData $inputData, InstallRunResultData $result): void
    {
        if ($this->input->isInteractive() && ! $this->shouldUseFreshDemoDefaults()) {
            $this->logInstallDebug('prompting for github star');
            $this->askToStarRepoOnGitHub('capell-app/capell');
            $this->processStarRepo();
        }

        $specOption = $this->option('spec');

        BuildAndAnnounceInstallSpecAction::run(
            is_string($specOption) ? $specOption : null,
            $this->orchestratedSeedDefaultData,
        );

        $handoff = BuildInstallHandoffAction::run(
            inputData: $inputData,
            result: $result,
            adminUrl: $this->installAdminUrl(),
            firstPageStatus: $this->installFirstPageStatus(),
            warnings: array_values(array_unique($this->manualInstallChanges)),
        );
        $handoffJson = $this->option('handoff-json');

        if (is_string($handoffJson) && trim($handoffJson) !== '') {
            $handoffPath = str_starts_with($handoffJson, DIRECTORY_SEPARATOR)
                ? $handoffJson
                : base_path($handoffJson);

            WriteInstallHandoffAction::run($handoff, $handoffPath);
        }

        resolve(InstallCommandPresenter::class)->outputHandoff(
            $handoff,
            is_string($handoffJson) && trim($handoffJson) !== '',
            $this->getOutput(),
            $this->outputComponents(),
        );
    }

    protected function shouldInstallAllPackages(): bool
    {
        if ($this->option('all-packages')) {
            return true;
        }

        return $this->shouldUseFreshDemoPackageDefaults();
    }

    protected function supportsPackageSelectionMode(): bool
    {
        return true;
    }

    /**
     * @param  Collection<string, PackageData>  $packages
     * @return array<int, string>
     */
    protected function installableExtraPackageNames(Collection $packages): array
    {
        return $packages
            ->keys()
            ->all();
    }

    private function bootInstallCommand(): ?int
    {
        $this->ensureInstallationMemoryLimit();

        $this->logInstallDebug('starting command', [
            'interactive' => $this->input->isInteractive(),
        ]);

        if ($this->option('production')) {
            if ($this->input->hasParameterOption('--fresh')) {
                $this->error('Refusing --fresh in --production mode (data-destructive). Drop --fresh or rerun without --production.');
                $this->logInstallDebug('production mode rejected --fresh');

                return CommandAlias::FAILURE;
            }

            $this->input->setInteractive(false);
            $this->logInstallDebug('production mode enabled', [
                'interactive' => false,
            ]);
        }

        [$installProfile, $installProfileExitCode] = $this->resolveInstallProfile();
        if ($installProfileExitCode !== null) {
            return $installProfileExitCode;
        }

        $this->installProfile = $installProfile;
        $this->applyInstallProfileDefaults();

        return null;
    }

    private function resolveSiteUrl(): string
    {
        $siteUrl = $this->option('url');
        if ($siteUrl === null) {
            $siteUrl = $this->defaultSiteUrl();
            $this->logInstallDebug('using default site url', [
                'site_url' => $siteUrl,
            ]);
        }

        if ($siteUrl === '') {
            $this->logInstallDebug('prompting for site url');
            $this->requireInteractiveOrFail('Site URL', 'Pass --url=<url>.');
            $siteUrl = text(label: 'What is the URL of the site?', default: $this->defaultSiteUrl(), required: true, validate: ['siteUrl' => 'url']);
        }

        $this->logInstallDebug('resolved site url', [
            'site_url' => $siteUrl,
        ]);

        return $siteUrl;
    }

    /**
     * @return Collection<string, PackageData>|null
     */
    private function resolveSelectedPackages(bool $demo, bool $freshInstall): ?Collection
    {
        try {
            $this->logInstallDebug('resolving selected packages');
            $packages = $this->getSelectedPackages();
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->error($invalidArgumentException->getMessage());
            $this->logInstallDebug('package selection failed', [
                'message' => $invalidArgumentException->getMessage(),
            ]);

            return null;
        }

        $packages = $demo && $this->shouldIncludeDemoPackagesAfterSelection()
            ? $this->includeDemoPackages($packages, $freshInstall)
            : $packages;

        $this->logInstallDebug('resolved selected packages', [
            'packages' => $packages->keys()->values()->all(),
        ]);

        return $packages;
    }

    private function installAdminUrl(): ?string
    {
        try {
            $panelId = (string) config('capell-admin.panel.id', 'admin');

            return Filament::getPanel($panelId)->getUrl();
        } catch (Throwable) {
            return null;
        }
    }

    private function installFirstPageStatus(): string
    {
        try {
            if (! Schema::hasTable('pages')) {
                return 'missing';
            }

            $page = Page::query()->with('blueprint')->first();

            if (! $page instanceof Page) {
                return 'missing';
            }

            return GetEditPageResourceUrlAction::run($page) !== null
                ? 'editable'
                : 'present_unverified';
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    private function ensureInstallationMemoryLimit(): void
    {
        $configuredLimit = ini_get('memory_limit');

        if (! is_string($configuredLimit) || $configuredLimit === '-1') {
            return;
        }

        if ($this->memoryLimitInBytes($configuredLimit) < self::MinimumMemoryLimitBytes) {
            ini_set('memory_limit', '512M');
        }
    }

    private function memoryLimitInBytes(string $configuredLimit): int
    {
        $multiplier = match (strtolower(substr($configuredLimit, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return (int) $configuredLimit * $multiplier;
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private function freshInstallOptions(): array
    {
        $freshOption = $this->option('fresh');

        if ($freshOption === null || $freshOption === false) {
            if ($this->input->hasParameterOption('--fresh')) {
                return [true, false];
            }

            return [false, false];
        }

        if (in_array($freshOption, [true, '', '1', 'true'], true)) {
            return [true, false];
        }

        if ($freshOption === 'force') {
            return [true, true];
        }

        throw new InvalidArgumentException('The --fresh option only accepts "force" when a value is supplied.');
    }

    private function confirmFreshInstall(bool $forceFreshInstall): bool
    {
        if ($forceFreshInstall) {
            return true;
        }

        if (confirm('Warning: this will delete all your data. Are you sure?', false)) {
            return true;
        }

        $this->warn('Fresh install cancelled.');

        return false;
    }

    private function shouldUseFreshDemoDefaults(): bool
    {
        [$freshInstall] = $this->freshInstallOptions();

        $useDefaults = $freshInstall
            && (bool) $this->option('demo')
            && ! FreshInstallDefaults::hasExplicitDemoInput([
                'url' => $this->input->getOption('url'),
                'user' => $this->input->getOption('user'),
                'name' => $this->input->getOption('name'),
                'email' => $this->input->getOption('email'),
                'password' => $this->input->getOption('password'),
                'theme' => $this->input->getOption('theme'),
            ]);

        $this->logInstallDebug('resolved fresh demo defaults', [
            'use_defaults' => $useDefaults,
            'fresh_install' => $freshInstall,
            'interactive' => $this->input->isInteractive(),
        ]);

        return $useDefaults;
    }

    private function shouldInstallDemoContent(): bool
    {
        return (bool) $this->option('demo');
    }

    /**
     * @return array{?InstallProfileData, ?int}
     */
    private function resolveInstallProfile(): array
    {
        $profileKey = $this->option('profile');

        if (! is_string($profileKey) || $profileKey === '') {
            return [null, null];
        }

        $profile = resolve(InstallProfileRepository::class)->find($profileKey);

        if (! $profile instanceof InstallProfileData) {
            $this->error(sprintf('Unknown install profile [%s].', $profileKey));

            return [null, CommandAlias::FAILURE];
        }

        return [$profile, null];
    }

    private function applyInstallProfileDefaults(): void
    {
        if (! $this->installProfile instanceof InstallProfileData) {
            return;
        }

        if ($this->installProfile->packages !== []
            && ! $this->optionWasProvidedOnCommandLine('packages')
            && ! $this->optionWasProvidedOnCommandLine('package-mode')
            && ! $this->optionWasProvidedOnCommandLine('all-packages')
        ) {
            $this->input->setOption('packages', implode(',', $this->installProfile->packages));
        }

        if ($this->installProfile->theme !== null && ! $this->optionWasProvidedOnCommandLine('theme')) {
            $this->input->setOption('theme', $this->installProfile->theme);
        }

        if ($this->installProfile->languages !== [] && ! $this->optionWasProvidedOnCommandLine('languages')) {
            $this->input->setOption('languages', implode(',', $this->installProfile->languages));
        }

        if ($this->installProfile->sites !== [] && ! $this->optionWasProvidedOnCommandLine('sites')) {
            $this->input->setOption('sites', implode(',', $this->installProfile->sites));
        }

        if ($this->installProfile->demo !== null && ! $this->optionWasProvidedOnCommandLine('demo')) {
            $this->input->setOption('demo', $this->installProfile->demo);
        }
    }

    private function optionWasProvidedOnCommandLine(string $option): bool
    {
        if ($this->input->hasParameterOption('--' . $option)) {
            return true;
        }

        return $this->input->hasParameterOption('--' . $option . '=');
    }

    /**
     * @param  Collection<string, PackageData>  $packages
     * @return Collection<string, PackageData>
     */
    private function includeDemoPackages(Collection $packages, bool $includeInstalledRequirements): Collection
    {
        return $this->packageSetComposer()->includeDemoPackages($packages, $includeInstalledRequirements);
    }

    private function shouldIncludeDemoPackagesAfterSelection(): bool
    {
        return $this->packageSetComposer()->shouldIncludeDemoPackagesAfterSelection(
            interactive: $this->input->isInteractive(),
            packagesOption: $this->option('packages'),
            packageModeOption: $this->option('package-mode'),
            allPackages: (bool) $this->option('all-packages'),
            useFreshDemoPackageDefaults: $this->shouldUseFreshDemoPackageDefaults(),
        );
    }

    private function shouldUseFreshDemoPackageDefaults(): bool
    {
        if (! $this->shouldUseFreshDemoDefaults()) {
            return false;
        }

        if (! $this->input->isInteractive()) {
            return true;
        }

        $packageMode = $this->input->hasOption('package-mode')
            ? $this->input->getOption('package-mode')
            : null;

        return $packageMode === 'all';
    }

    /**
     * @return array<int, string>
     */
    private function resolveLanguages(): array
    {
        $languages = $this->parseListOption('languages');

        if ($languages !== null) {
            return $languages;
        }

        if ($this->option('demo')) {
            return FreshInstallDefaults::demoLanguages(config('app.locale', 'en'));
        }

        return [config('app.locale', 'en')];
    }

    /**
     * @return array<int, string>
     */
    private function resolveSites(): array
    {
        $sites = $this->parseListOption('sites');

        if ($sites !== null) {
            return $sites;
        }

        if ($this->option('demo')) {
            return FreshInstallDefaults::demoSites(config('app.name', 'Capell Application'));
        }

        return [config('app.name', 'Capell Application')];
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
     * @return array<int, string>
     */
    private function installTimePackageNamesFromSelection(): array
    {
        return $this->packageSetComposer()->installTimePackageNames(
            selectedPackageNames: $this->parseListOption('packages') ?? [],
            packageMode: $this->option('package-mode'),
            allPackages: (bool) $this->option('all-packages'),
            useFreshDemoPackageDefaults: $this->shouldUseFreshDemoPackageDefaults(),
        );
    }

    private function installerPackageName(): string
    {
        return 'capell-app/installer';
    }

    private function userPrompter(): InstallUserPrompter
    {
        return new InstallUserPrompter(
            interactive: $this->input->isInteractive(),
            writeError: function (string $message): void {
                $this->error($message);
            },
            writeLine: function (string $message): void {
                $this->line($message);
            },
            logDebug: function (string $message, array $context): void {
                $this->logInstallDebug($message, $context);
            },
            requireInteractiveOrFail: function (string $requirement, string $hint): void {
                $this->requireInteractiveOrFail($requirement, $hint);
            },
        );
    }

    private function stringConfigValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function defaultSiteUrl(): string
    {
        return $this->stringConfigValue(config('app.url'));
    }

    private function recordManualInstallChange(string $message): void
    {
        $this->manualInstallChanges[] = $message;
    }

    /**
     * @return array{?string, ?int}
     */
    private function resolveThemeSelection(): array
    {
        return $this->packageSetComposer()->resolveThemeSelection(
            themeOption: $this->option('theme'),
            interactive: $this->input->isInteractive(),
            useFreshDemoDefaults: $this->shouldUseFreshDemoDefaults(),
            writeError: function (string $message): void {
                $this->error($message);
            },
        );
    }

    /**
     * @param  Collection<string, PackageData>  $selectedPackages
     * @return array{Collection<string, PackageData>, array<int, string>}
     */
    private function includeSelectedThemePackage(Collection $selectedPackages, ?string $selectedThemeKey, bool $includeInstalledRequirements): array
    {
        return $this->packageSetComposer()->includeSelectedThemePackage(
            $selectedPackages,
            $selectedThemeKey,
            $includeInstalledRequirements,
        );
    }

    private function packageSetComposer(): InstallPackageSetComposer
    {
        return resolve(InstallPackageSetComposer::class);
    }

    private function isInstalled(): bool
    {
        foreach (CapellCore::getModels() as $modelClass) {
            if (! Schema::hasTable((new $modelClass)->getTable())) {
                return false;
            }
        }

        return Site::query()->count() !== 0;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logInstallDebug(string $event, array $context = []): void
    {
        if (config('capell.install.debug') !== true) {
            return;
        }

        Log::debug('capell.install: ' . $event, [
            ...$context,
            'fresh_option' => $this->input->hasOption('fresh')
                ? $this->input->getOption('fresh')
                : null,
            'demo_option' => $this->input->hasOption('demo')
                ? $this->input->getOption('demo')
                : null,
            'package_mode_option' => $this->input->hasOption('package-mode')
                ? $this->input->getOption('package-mode')
                : null,
            'packages_option' => $this->input->hasOption('packages')
                ? $this->input->getOption('packages')
                : null,
            'theme_option' => $this->input->hasOption('theme')
                ? $this->input->getOption('theme')
                : null,
            'url_option' => $this->input->hasOption('url')
                ? $this->input->getOption('url')
                : null,
        ]);
    }
}
