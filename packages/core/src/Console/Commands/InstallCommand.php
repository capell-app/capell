<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\BuildSiteFromSpecFileAction;
use Capell\Core\Actions\Install\InstallFilamentPanelAction;
use Capell\Core\Actions\Install\OrchestrateInstallAction;
use Capell\Core\Actions\RemovePackageAction;
use Capell\Core\Actions\RunNpmBuildAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Console\Commands\Concerns\HasPackageSelection;
use Capell\Core\Console\Commands\Concerns\PromptsWithOptionFallback;
use Capell\Core\Contracts\InstallOrchestrationHost;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\Install\InstallOrchestrationData;
use Capell\Core\Data\Install\InstallProfileData;
use Capell\Core\Data\Install\InstallStepData;
use Capell\Core\Data\Install\ThemeInstallOptionData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\NewUserData;
use Capell\Core\Data\PackageData;
use Capell\Core\Events\CapellInstalled;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Capell\Core\Support\Install\ConsoleProgressReporter;
use Capell\Core\Support\Install\DeveloperToolingInstallationState;
use Capell\Core\Support\Install\InstallInputFactory;
use Capell\Core\Support\Install\InstallPatchConfirmation;
use Capell\Core\Support\Install\InstallPatchContext;
use Capell\Core\Support\Install\InstallPatchRegistry;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Install\InstallProfileRepository;
use Capell\Core\Support\Install\PackageWorkflowPlanner;
use Capell\Core\Support\Install\ThemePackageCandidates;
use Capell\Core\Support\Install\WelcomeRouteInstaller;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Capell\Core\Support\Patching\PatchStatus;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\password;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
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

        $planOnly = $this->option('plan');
        $noSideEffects = $this->option('no-side-effects');
        [$freshInstall, $forceFreshInstall] = $this->freshInstallOptions();
        $demo = $this->shouldInstallDemoContent();
        $userEmailOption = $this->option('user');
        $newUser = $this->newUserFromOptions();
        $clearCache = $this->option('clear-cache');
        $generateSitemap = $this->option('generate-sitemap');
        $seedDatabase = (bool) $this->option('seed');
        $seedDefaultData = ! $this->option('no-seed-default-data');
        $freshInstallConfirmed = false;

        $this->writeCommandIntro(
            'install Capell',
            $this->installCommandIntroDetails($freshInstall, $forceFreshInstall, $demo, $planOnly, $noSideEffects),
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

        try {
            $this->logInstallDebug('resolving selected packages');
            $packages = $this->getSelectedPackages();
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->error($invalidArgumentException->getMessage());
            $this->logInstallDebug('package selection failed', [
                'message' => $invalidArgumentException->getMessage(),
            ]);

            return CommandAlias::FAILURE;
        }

        $packages = $demo && $this->shouldIncludeDemoPackagesAfterSelection()
            ? $this->includeDemoPackages($packages, $freshInstall)
            : $packages;

        $this->logInstallDebug('resolved selected packages', [
            'packages' => $packages->keys()->values()->all(),
        ]);

        if ($packages->isEmpty()) {
            $this->warn('No packages selected.');
        }

        $reporter = new ConsoleProgressReporter($this);

        if (! $planOnly && ! $this->ensureFilamentIsInstalledForAdmin($packages, $reporter)) {
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
        $installTimePackageNames = $this->installTimePackageNamesFromPackagesOption();
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
            : $this->shouldInstallWelcomeRoute($hasFrontend);
        $this->logInstallDebug('resolved welcome route option', [
            'has_frontend' => $hasFrontend,
            'install_welcome_route' => $installWelcomeRoute,
        ]);

        if ($planOnly) {
            $this->logInstallDebug('building plan-only input');
            [$installDeveloperTooling, $configureBoostDeveloperTooling] = $this->developerToolingOptionsForPlan();

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
                installDeveloperTooling: $installDeveloperTooling,
                configureBoostDeveloperTooling: $configureBoostDeveloperTooling,
                selectedThemeKey: resolve(ThemePackageCandidates::class)->inputThemeKey($selectedThemeKey),
                extraPackages: array_values(array_unique([...$installTimePackageNames, ...$themeExtraPackages])),
            );

            $this->outputPlan($inputData);
            $this->logInstallDebug('finished plan-only command');

            return CommandAlias::SUCCESS;
        }

        $this->logInstallDebug('resolving admin user');
        [$userId, $resolvedNewUser, $exitCode] = $this->resolveUserInput($userEmailOption, $newUser, $freshInstall);
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

        [$additionalUsers, $additionalUsersExitCode] = $this->resolveAdditionalUsersInput();
        if ($additionalUsersExitCode !== null) {
            $this->logInstallDebug('additional user resolution failed', [
                'exit_code' => $additionalUsersExitCode,
            ]);

            return $additionalUsersExitCode;
        }

        $this->logInstallDebug('resolved additional users', [
            'count' => count($additionalUsers),
        ]);

        [$installDeveloperTooling, $configureBoostDeveloperTooling] = $this->developerToolingOptions();
        $this->logInstallDebug('resolved developer tooling', [
            'install_developer_tooling' => $installDeveloperTooling,
            'configure_boost_developer_tooling' => $configureBoostDeveloperTooling,
        ]);

        $runNpmBuild = $this->shouldRunNpmBuild($hasFrontend);
        $removeInstallerPackage = $this->shouldRemoveInstallerPackage();
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
            installDeveloperTooling: $installDeveloperTooling,
            configureBoostDeveloperTooling: $configureBoostDeveloperTooling,
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
                    cachesToClear: $this->resolveCachesToClear($clearCache, $freshInstall),
                ),
                $reporter,
                $this,
            );
            $this->logInstallDebug('install orchestration finished');
        } catch (Throwable $throwable) {
            $this->renderInstallFailure($throwable);

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
        $selectedPackageNames = array_values(array_unique([
            ...$inputData->packages,
            ...$inputData->extraPackages,
        ]));

        $patchContext = new InstallPatchContext(
            packageNames: $selectedPackageNames,
            hasFilamentAdminPanelProvider: $this->hasFilamentAdminPanelProvider(),
        );

        foreach (resolve(InstallPatchRegistry::class)->patchesFor($patchContext) as $registeredPatch) {
            $patch = $registeredPatch->patch;
            $status = $patch->probe();

            if ($status === PatchStatus::AlreadyApplied) {
                continue;
            }

            if ($status !== PatchStatus::Applicable) {
                $this->recordManualInstallChange(sprintf(
                    '%s: patch status is "%s".',
                    $patch->label(),
                    $status->value,
                ));

                $reporter->error(sprintf(
                    '⚠ %s was not applied automatically. Manual changes may be required.',
                    $patch->label(),
                ));

                continue;
            }

            $confirmation = $registeredPatch->confirmation;

            if ($confirmation instanceof InstallPatchConfirmation
                && $this->input->isInteractive()
                && ! $this->shouldUseFreshDemoDefaults()
                && ! confirm(
                    label: $confirmation->label,
                    default: $confirmation->default,
                    hint: $confirmation->hint ?? '',
                )
            ) {
                if ($confirmation->skippedMessage !== null) {
                    $reporter->report($confirmation->skippedMessage);
                }

                continue;
            }

            $reporter->step('Applying install guide patch: ' . $patch->label());

            try {
                $patch->apply();
            } catch (Throwable $throwable) {
                $this->recordManualInstallChange(sprintf(
                    '%s: %s',
                    $patch->label(),
                    $throwable->getMessage(),
                ));

                $reporter->error(sprintf(
                    '⚠ %s was not applied automatically. Manual changes may be required.',
                    $patch->label(),
                ));
                $reporter->error($throwable->getMessage());
            }
        }
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

    public function finalizeInstall(): void
    {
        if ($this->input->isInteractive() && ! $this->shouldUseFreshDemoDefaults()) {
            $this->logInstallDebug('prompting for github star');
            $this->askToStarRepoOnGitHub('capell-app/capell');
            $this->processStarRepo();
        }

        $this->announceInstallSpec($this->orchestratedSeedDefaultData);
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

    /**
     * After a successful install, consume a supplied site spec in Core, then
     * announce it so extensions can perform compatible post-build work.
     */
    private function announceInstallSpec(bool $seededDefaults): void
    {
        $specOption = $this->option('spec');

        if (! is_string($specOption) || $specOption === '') {
            return;
        }

        $resolvedPath = realpath($specOption);
        $specPath = $resolvedPath === false ? $specOption : $resolvedPath;

        BuildSiteFromSpecFileAction::run($specPath);
        Event::dispatch(new CapellInstalled($specPath, $seededDefaults));
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
            && ! $this->hasExplicitFreshDemoInput();

        $this->logInstallDebug('resolved fresh demo defaults', [
            'use_defaults' => $useDefaults,
            'fresh_install' => $freshInstall,
            'interactive' => $this->input->isInteractive(),
        ]);

        return $useDefaults;
    }

    private function hasExplicitFreshDemoInput(): bool
    {
        foreach (['url', 'user', 'name', 'email', 'password', 'theme'] as $optionName) {
            $value = $this->input->getOption($optionName);

            if (! in_array($value, [null, false, ''], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function resolveCachesToClear(bool $clearCache, bool $freshInstall): array
    {
        if ($clearCache || $freshInstall) {
            return ['all'];
        }

        $cacheOptions = $this->cacheOptions();

        return array_map(static fn (int|string $cache): string => (string) $cache, multiselect(
            label: 'Which caches would you like to clear?',
            options: $cacheOptions,
            default: $this->defaultCachesToClear($cacheOptions),
        ));
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

    private function renderInstallFailure(Throwable $throwable): void
    {
        report($throwable);

        $message = trim($throwable->getMessage());

        $this->newLine();
        $this->error('Capell installation failed.');

        if ($message !== '') {
            $this->line($message);
        }

        $this->line('Run the command again with CAPELL_INSTALL_DEBUG=1 for step-level diagnostics.');

        $this->logInstallDebug('install action failed', [
            'exception' => $throwable::class,
            'message' => $throwable->getMessage(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function installCommandIntroDetails(
        bool $freshInstall,
        bool $forceFreshInstall,
        bool $demo,
        bool $planOnly,
        bool $noSideEffects,
    ): array {
        $details = [];

        if ($freshInstall) {
            $details[] = $forceFreshInstall
                ? 'a forced fresh database refresh'
                : 'a fresh database refresh';
        }

        if ($demo) {
            $details[] = 'demo content';
        }

        if ($planOnly) {
            $details[] = 'a plan-only preview';
        }

        if ($noSideEffects) {
            $details[] = 'side effects disabled';
        }

        return $details;
    }

    /**
     * @param  Collection<string, PackageData>  $packages
     * @return Collection<string, PackageData>
     */
    private function includeDemoPackages(Collection $packages, bool $includeInstalledRequirements): Collection
    {
        $demoPackageNames = CapellCore::getPackages(sortByDependencies: true)
            ->filter(fn (PackageData $package): bool => $package->isDemo())
            ->keys();

        if ($demoPackageNames->isEmpty()) {
            return $packages;
        }

        $withDemoPackages = $packages->keys()
            ->merge($demoPackageNames)
            ->unique()
            ->values()
            ->all();

        if ($packages->keys()->all() === $withDemoPackages) {
            return $packages;
        }

        return resolve(PackageWorkflowPlanner::class)->expandAndOrder(
            CapellCore::getPackages(sortByDependencies: true),
            $withDemoPackages,
            $includeInstalledRequirements,
        );
    }

    private function shouldIncludeDemoPackagesAfterSelection(): bool
    {
        if (! $this->input->isInteractive()) {
            return true;
        }

        if ($this->option('packages') !== null) {
            return true;
        }

        if ($this->option('package-mode') !== null) {
            return true;
        }

        if ($this->option('all-packages')) {
            return true;
        }

        return $this->shouldUseFreshDemoPackageDefaults();
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
            return array_values(array_unique([
                'en',
                config('app.locale', 'en'),
                'fr',
                'de',
            ]));
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
            return [
                config('app.name', 'Capell Application'),
                'Capell Knowledge',
                'Capell Services',
            ];
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
    private function installTimePackageNamesFromPackagesOption(): array
    {
        return collect($this->parseListOption('packages') ?? [])
            ->filter(fn (string $packageName): bool => TrustedCorePackages::contains($packageName))
            ->reject(fn (string $packageName): bool => CapellCore::hasPackage($packageName))
            ->values()
            ->all();
    }

    private function shouldRunNpmBuild(bool $hasFrontend): bool
    {
        if (! $hasFrontend) {
            return false;
        }

        if (! $this->input->isInteractive() || $this->shouldUseFreshDemoDefaults()) {
            return false;
        }

        return confirm('Would you like to run an npm build after this command completes?', default: true);
    }

    private function shouldRemoveInstallerPackage(): bool
    {
        if (! CapellCore::hasPackage($this->installerPackageName())) {
            return false;
        }

        if ($this->option('remove-installer')) {
            return true;
        }

        if (! $this->input->isInteractive() || $this->shouldUseFreshDemoDefaults()) {
            return false;
        }

        return confirm(
            label: 'Delete the installer after installing?',
            hint: 'You can download again by composer require `capell-app/installer`',
        );
    }

    private function shouldInstallWelcomeRoute(bool $hasFrontend): bool
    {
        if (! $hasFrontend) {
            return false;
        }

        if ($this->option('install-welcome-route')) {
            return true;
        }

        $welcomeRouteInstaller = resolve(WelcomeRouteInstaller::class);

        if (! $welcomeRouteInstaller->canInstall()) {
            return false;
        }

        if (! $this->input->isInteractive()) {
            return false;
        }

        $removeExistingHomeRoute = confirm(
            label: 'Remove existing home route?',
            default: true,
            hint: "Removes Laravel's default welcome route so Capell CMS can handle the homepage.",
        );

        if (! $removeExistingHomeRoute) {
            $this->configureWelcomeRouteManuallyOnFailure(
                function () use ($welcomeRouteInstaller): void {
                    $welcomeRouteInstaller->disableFrontendHomeRoute();
                },
                'Set CAPELL_FRONTEND_REGISTER_HOME_ROUTE=false in .env.',
            );
        } else {
            $this->configureWelcomeRouteManuallyOnFailure(
                function () use ($welcomeRouteInstaller): void {
                    $welcomeRouteInstaller->enableFrontendHomeRoute();
                },
                'Set CAPELL_FRONTEND_REGISTER_HOME_ROUTE=true in .env.',
            );
        }

        return $removeExistingHomeRoute;
    }

    private function configureWelcomeRouteManuallyOnFailure(callable $callback, string $manualChange): void
    {
        try {
            $callback();
        } catch (Throwable $throwable) {
            $this->recordManualInstallChange($manualChange . ' ' . $throwable->getMessage());
            $this->warn('Unable to update .env automatically. Manual changes may be required.');
        }
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private function developerToolingOptions(): array
    {
        if ($this->option('developer-tooling')) {
            return [true, ! $this->option('no-boost-install')];
        }

        if (resolve(DeveloperToolingInstallationState::class)->isInstalled()) {
            return [true, false];
        }

        if (! $this->input->isInteractive() || $this->shouldUseFreshDemoDefaults()) {
            return [false, false];
        }

        if (! confirm(
            label: 'Install AI / Agent Bridge developer tooling?',
            default: false,
            hint: 'Installs Laravel Boost and Capell Agent Bridge for local agent workflows.',
        )) {
            return [false, false];
        }

        $configureBoostDeveloperTooling = confirm(
            label: 'Run Laravel Boost installer for Agent Bridge, guidelines, and skills?',
            default: true,
            hint: 'Runs boost:install --guidelines --skills --mcp without interaction.',
        );

        return [true, $configureBoostDeveloperTooling];
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private function developerToolingOptionsForPlan(): array
    {
        if ($this->option('developer-tooling')) {
            return [true, ! $this->option('no-boost-install')];
        }

        if (resolve(DeveloperToolingInstallationState::class)->isInstalled()) {
            return [true, false];
        }

        return [false, false];
    }

    /** @return array<string, string> */
    private function cacheOptions(): array
    {
        $options = $this->baseCacheOptions();

        foreach ($this->optionalCacheOptions() as $key => $option) {
            if ($this->getApplication()?->has($option['command']) === true) {
                $options[$key] = $option['label'];
            }
        }

        return $options;
    }

    /**
     * @param  array<string, string>  $cacheOptions
     * @return array<string>
     */
    private function defaultCachesToClear(array $cacheOptions): array
    {
        return array_values(array_filter(
            $this->defaultCacheKeys(),
            fn (string $cacheKey): bool => array_key_exists($cacheKey, $cacheOptions),
        ));
    }

    /**
     * @return list<string>
     */
    private function defaultCacheKeys(): array
    {
        return [
            'page',
            'config',
            'views',
            'admin',
            'components',
            'widgets',
            'configurators',
            'filament-components',
        ];
    }

    private function createAdminUserOption(): string
    {
        return '__create_admin_user__';
    }

    private function useExistingAdminUserOption(): string
    {
        return 'existing';
    }

    private function installerPackageName(): string
    {
        return 'capell-app/installer';
    }

    /**
     * @return array<string, string>
     */
    private function baseCacheOptions(): array
    {
        return [
            'all' => 'Laravel optimized caches',
            'page' => 'Page cache',
            'config' => 'Config cache',
            'views' => 'Views cache',
        ];
    }

    /**
     * @return array<string, array{label: string, command: string}>
     */
    private function optionalCacheOptions(): array
    {
        return [
            'admin' => [
                'label' => 'Capell admin cache',
                'command' => 'capell:admin-clear-cache',
            ],
            'components' => [
                'label' => 'Capell components cache',
                'command' => 'capell:clear-components-cache',
            ],
            'widgets' => [
                'label' => 'Capell widgets cache',
                'command' => 'capell:admin-clear-widgets-cache',
            ],
            'configurators' => [
                'label' => 'Capell configurators cache',
                'command' => 'capell:admin-clear-configurators-cache',
            ],
            'filament-components' => [
                'label' => 'Filament components cache',
                'command' => 'filament:clear-cached-components',
            ],
        ];
    }

    /** @return array{?int, ?NewUserData, ?int} */
    private function resolveUserInput(?string $userEmailOption, ?NewUserData $newUserOption, bool $freshInstall): array
    {
        /** @var class-string<User> $userModel */
        $userModel = config('auth.providers.users.model');
        $userTable = (new $userModel)->getTable();

        if ($userEmailOption !== null && $newUserOption instanceof NewUserData) {
            $this->error('Use either --user for an existing user or --name/--email/--password for a new user, not both.');

            return [null, null, CommandAlias::FAILURE];
        }

        if ($newUserOption instanceof NewUserData) {
            return [null, $newUserOption, null];
        }

        if ($freshInstall && $this->shouldUseFreshDemoDefaults()) {
            return [null, $this->defaultDemoAdminUser(), null];
        }

        if (! Schema::hasTable($userTable)) {
            if ($userEmailOption !== null) {
                $this->error('User table not found: ' . $userTable);

                return [null, null, CommandAlias::FAILURE];
            }

            return [null, $this->promptForNewUser(), null];
        }

        if ($userEmailOption !== null) {
            $user = $userModel::query()->where('email', $userEmailOption)->first();
            if ($user === null) {
                $this->error('User not found: ' . $userEmailOption);

                return [null, null, CommandAlias::FAILURE];
            }

            return [$user->getKey(), null, null];
        }

        if ($freshInstall) {
            return [null, $this->promptForNewUser(), null];
        }

        $totalUsers = $userModel::query()->count();

        if ($totalUsers === 0) {
            return [null, $this->promptForNewUser(), null];
        }

        $adminUserMode = select(
            label: 'Which admin user should we use?',
            options: [
                $this->useExistingAdminUserOption() => 'Use an existing user',
                $this->createAdminUserOption() => 'Create a new admin user',
            ],
            default: $this->useExistingAdminUserOption(),
        );

        if ($adminUserMode === $this->createAdminUserOption()) {
            return [null, $this->promptForNewUser(), null];
        }

        $selectedUser = search(
            label: 'Search for an existing admin user',
            options: fn (string $search): array => $this->existingUserOptions($userModel, $search),
            validate: fn (int|string|null $value): ?string => $this->validateInstallUserSelection($value, $userTable),
        );

        return [(int) $selectedUser, null, null];
    }

    /**
     * @param  class-string<User>  $userModel
     * @return array<int|string, string>
     */
    private function existingUserOptions(string $userModel, string $search): array
    {
        return $userModel::query()
            ->when(mb_strlen($search) > 0, fn (Builder $query) => $query->whereAny(['name', 'email'], 'like', $search . '%'))
            ->limit(10)
            ->select(['id', 'name', 'email'])
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->getKey() => sprintf('%s <%s>', $user->name, $user->email),
            ])
            ->all();
    }

    private function validateInstallUserSelection(int|string|null $value, string $userTable): ?string
    {
        if ($value === $this->createAdminUserOption()) {
            return null;
        }

        if (! is_int($value) && ! ctype_digit((string) $value)) {
            return 'Select an existing user or create a new admin user.';
        }

        return Schema::hasTable($userTable) && DB::table($userTable)->where('id', (int) $value)->exists()
            ? null
            : 'The selected user does not exist.';
    }

    private function newUserFromOptions(): ?NewUserData
    {
        $name = $this->option('name');
        $email = $this->option('email');
        $password = $this->option('password');

        $hasAnyOption = $name !== null || $email !== null || $password !== null;
        if ($hasAnyOption) {
            throw_if(
                ! is_string($name) || $name === '' || ! is_string($email) || $email === '' || ! is_string($password) || $password === '',
                InvalidArgumentException::class,
                'Pass --name, --email, and --password together to create the first user non-interactively.',
            );

            return new NewUserData(name: $name, email: $email, password: $password);
        }

        return $this->newUserFromInstallerConfig();
    }

    private function newUserFromInstallerConfig(): ?NewUserData
    {
        $configured = config('capell-installer.admin_user');
        if (! is_array($configured)) {
            $configured = config('capell.install.admin_user', []);
        }

        if (! is_array($configured)) {
            return null;
        }

        $name = $this->stringConfigValue($configured['name'] ?? null);
        $email = $this->stringConfigValue($configured['email'] ?? null);
        $password = $this->stringConfigValue($configured['password'] ?? null);

        if ($name === '' || $email === '' || $password === '') {
            return null;
        }

        return new NewUserData(name: $name, email: $email, password: $password);
    }

    private function stringConfigValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function defaultSiteUrl(): string
    {
        return $this->stringConfigValue(config('app.url'));
    }

    private function promptForNewUser(): NewUserData
    {
        $this->line('Please enter details for the admin user who can log in to Capell.');
        $name = text(label: 'Name', required: true);
        $email = text(label: 'Email', required: true, validate: ['email' => 'email']);
        $password = password(label: 'Password', required: true);

        return new NewUserData(name: $name, email: $email, password: $password);
    }

    private function defaultDemoAdminUser(): NewUserData
    {
        $this->logInstallDebug('using default fresh demo admin user', [
            'email' => 'admin@example.test',
        ]);

        return new NewUserData(
            name: 'Capell Admin',
            email: 'admin@example.test',
            password: 'password',
        );
    }

    /**
     * @return array{array<NewUserData>, ?int}
     */
    private function resolveAdditionalUsersInput(): array
    {
        $createRoleUsers = $this->option('role-users');

        if (! $createRoleUsers) {
            return [[], null];
        }

        $password = $this->option('role-user-password');

        if (! is_string($password) || $password === '') {
            if (! $this->input->isInteractive()) {
                $this->error('Pass --role-user-password=<password> when using --role-users non-interactively.');

                return [[], CommandAlias::FAILURE];
            }

            $password = password(label: 'Example role user password', required: true);
        }

        return [resolve(InstallInputFactory::class)->exampleRoleUsers($password), null];
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
        $themeOption = $this->option('theme');
        $themeCandidates = $this->themeCandidates();

        if (is_string($themeOption) && $themeOption !== '') {
            $normalisedThemeOption = resolve(ThemePackageCandidates::class)->inputThemeKey($themeOption);
            $themeCandidateKey = $normalisedThemeOption ?? $themeOption;

            if (! array_key_exists($themeCandidateKey, $themeCandidates)) {
                $this->error(sprintf(
                    'Unknown theme [%s]. Available themes: %s.',
                    $themeOption,
                    $this->formatThemeCandidatesForConsole($themeCandidates),
                ));

                return [null, CommandAlias::FAILURE];
            }

            return [$normalisedThemeOption, null];
        }

        $defaultThemeKey = resolve(ThemePackageCandidates::class)->defaultThemeKeyForCatalogue();

        if ($this->input->isInteractive() && ! $this->shouldUseFreshDemoDefaults()) {
            return [
                (string) select(
                    label: 'Which starter theme should be installed?',
                    options: $themeCandidates,
                    default: $defaultThemeKey,
                ),
                null,
            ];
        }

        return [$defaultThemeKey, null];
    }

    /**
     * @return array<string, string>
     */
    private function themeCandidates(): array
    {
        return collect(resolve(ThemePackageCandidates::class)
            ->optionDataForCatalogue())
            ->mapWithKeys(fn (ThemeInstallOptionData $option): array => [$option->key => $option->consoleLabel()])
            ->all();
    }

    /**
     * @param  array<string, string>  $themeCandidates
     */
    private function formatThemeCandidatesForConsole(array $themeCandidates): string
    {
        return collect($themeCandidates)
            ->map(fn (string $label, string $themeKey): string => sprintf('%s (%s)', $themeKey, $label))
            ->implode(', ');
    }

    /**
     * @param  Collection<string, PackageData>  $selectedPackages
     * @return array{Collection<string, PackageData>, array<int, string>}
     */
    private function includeSelectedThemePackage(Collection $selectedPackages, ?string $selectedThemeKey, bool $includeInstalledRequirements): array
    {
        if ($selectedThemeKey === null || $selectedThemeKey === ThemePackageCandidates::NONE_KEY) {
            return [$selectedPackages, []];
        }

        $themeOptions = resolve(ThemePackageCandidates::class)
            ->optionDataForCatalogue();
        $packageName = $themeOptions[$selectedThemeKey]->packageName ?? null;

        if ($packageName === null || $selectedPackages->has($packageName)) {
            return [$selectedPackages, []];
        }

        if (! CapellCore::hasPackage($packageName)) {
            return [$selectedPackages, [$packageName]];
        }

        $packageNames = $selectedPackages->keys()
            ->push($packageName)
            ->unique()
            ->values()
            ->all();

        return [
            resolve(PackageWorkflowPlanner::class)->expandAndOrder(
                CapellCore::getPackages(sortByDependencies: true),
                $packageNames,
                $includeInstalledRequirements,
            ),
            [],
        ];
    }

    /**
     * @param  Collection<string, PackageData>  $packages
     */
    private function ensureFilamentIsInstalledForAdmin(Collection $packages, ConsoleProgressReporter $reporter): bool
    {
        if (! $packages->has('capell-app/admin')) {
            return true;
        }

        if (! $this->hasFilamentAdminPanelProvider()) {
            if ($this->input->isInteractive()
                && ! $this->shouldUseFreshDemoDefaults()
                && ! confirm(
                    label: 'The Capell admin package requires a Filament panel. Would you like to install Filament now?',
                    default: true,
                )) {
                $this->error('Filament must be installed before installing the Capell admin package.');

                return false;
            }

            try {
                InstallFilamentPanelAction::run($reporter);
            } catch (Throwable $throwable) {
                $this->error($throwable->getMessage());

                return false;
            }
        }

        $this->registerFilamentAdminPanelProviders();

        if (! $this->hasFilamentAdminPanelProvider()) {
            $this->error('Filament panel installation did not create an AdminPanelProvider. Run `php artisan filament:install --panels` manually, then rerun `php artisan capell:install`.');

            return false;
        }

        return true;
    }

    private function registerFilamentAdminPanelProviders(): void
    {
        foreach ($this->filamentAdminPanelProviderPaths() as $path) {
            $relativePath = str_replace(app_path() . DIRECTORY_SEPARATOR, '', $path);
            $class = 'App\\' . str_replace(['/', '.php'], ['\\', ''], $relativePath);

            if (! class_exists($class)) {
                require_once $path;
            }

            if (class_exists($class)) {
                app()->register($class);
            }
        }
    }

    private function hasFilamentAdminPanelProvider(): bool
    {
        return $this->filamentAdminPanelProviderPaths() !== [];
    }

    /**
     * @return array<int, string>
     */
    private function filamentAdminPanelProviderPaths(): array
    {
        $paths = glob(app_path('Providers/Filament/*PanelProvider.php'));

        if (! is_array($paths)) {
            return [];
        }

        return array_values(array_filter($paths, is_file(...)));
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
