<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Actions\Install\BuildAndAnnounceInstallSpecAction;
use Capell\Core\Actions\Install\BuildInstallFrontendAssetsAction;
use Capell\Core\Actions\Install\BuildInstallHandoffAction;
use Capell\Core\Actions\Install\BuildInstallReviewAction;
use Capell\Core\Actions\Install\CanCreateInstallAdministratorAction;
use Capell\Core\Actions\Install\OrchestrateInstallAction;
use Capell\Core\Actions\Install\PrepareInstallApplicationAction;
use Capell\Core\Actions\Install\RunArtisanCommandAction;
use Capell\Core\Actions\Install\RunInstallPreflightChecksAction;
use Capell\Core\Actions\Install\SaveInstallProfileAction;
use Capell\Core\Actions\Install\UpdateInstallAppUrlAction;
use Capell\Core\Actions\Install\WriteInstallHandoffAction;
use Capell\Core\Actions\RemovePackageAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Console\Commands\Concerns\HasPackageSelection;
use Capell\Core\Console\Commands\Concerns\PromptsWithOptionFallback;
use Capell\Core\Contracts\AdminPanelUrlResolver;
use Capell\Core\Contracts\InstallOrchestrationHost;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\Install\DeveloperToolingChoiceData;
use Capell\Core\Data\Install\InstallOrchestrationData;
use Capell\Core\Data\Install\InstallProfileData;
use Capell\Core\Data\Install\InstallRunResultData;
use Capell\Core\Data\Install\InstallStepData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\NewUserData;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Filesystem\AbsolutePath;
use Capell\Core\Support\Install\Cli\FilamentAdminInstallPreflight;
use Capell\Core\Support\Install\Cli\FreshInstallDefaults;
use Capell\Core\Support\Install\Cli\InstallCacheOptionResolver;
use Capell\Core\Support\Install\Cli\InstallCommandPresenter;
use Capell\Core\Support\Install\Cli\InstallPackageSetComposer;
use Capell\Core\Support\Install\Cli\InstallPostInstallOptionResolver;
use Capell\Core\Support\Install\Cli\InstallSuitePrompter;
use Capell\Core\Support\Install\Cli\InstallUserPrompter;
use Capell\Core\Support\Install\ConsoleProgressReporter;
use Capell\Core\Support\Install\DeveloperToolingInstallationState;
use Capell\Core\Support\Install\InstallInputFactory;
use Capell\Core\Support\Install\InstallPatchConfirmation;
use Capell\Core\Support\Install\InstallPatchContext;
use Capell\Core\Support\Install\InstallPatchRegistry;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Install\InstallProfileRepository;
use Capell\Core\Support\Install\InstallRecommendationRepository;
use Capell\Core\Support\Install\InstallSiteUrl;
use Capell\Core\Support\Install\ThemePackageCandidates;
use Capell\Core\Support\Install\WelcomeRouteInstaller;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Capell\Core\Support\Patching\PatchStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use Override;
use RuntimeException;
use Spatie\LaravelPackageTools\Commands\Concerns\AskToStarRepoOnGitHub;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Symfony\Component\Console\Formatter\OutputFormatter;
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
        {--customise : Open the detailed installation questionnaire}
        {--build-assets : Install frontend dependencies and build assets after confirmation}
        {--save-profile= : Save reviewed non-secret settings to a named installation profile}
        {--update-app-url : Set APP_URL to the reviewed site address after confirmation}
        {--demo}
        {--allow-demo-credentials : Explicitly allow the known demo administrator credentials in production}
        {--plan : Print the exact install plan and exit without mutation}
        {--fresh= : Refresh the database before installing; pass force to skip the confirmation}
        {--profile= : Install profile key from config/capell-install-profiles.php or capell-install-profiles.json}
        {--recommendation= : Deterministic install bundle key (for example blog, marketing, or headless)}
        {--recommendation-action= : Recommendation action: select, confirm, custom, or skip}
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
        {--url= : First site URL, including localhost or a Docker published host port and optional path (prompts interactively; otherwise defaults to APP_URL)}
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

    private bool $orchestratedBuildAssets = false;

    /** @var array<int, bool> */
    private array $reviewedPatchChoices = [];

    private bool $configureHomepage = false;

    /** @var list<string> Suite extensions that are not installed yet and must be downloaded with Composer. */
    private array $suiteDownloadPackages = [];

    /** The chosen suite's theme, offered as the default of the theme question rather than forced. */
    private ?string $suiteThemeKey = null;

    private ?string $resolvedSiteUrl = null;

    private bool $basicInstall = false;

    private bool $restartReview = false;

    private bool $wizardBooted = false;

    private bool $freshConfirmed = false;

    private bool $editAdministrator = false;

    private ?DeveloperToolingChoiceData $toolingChoice = null;

    private ?bool $buildAssetsChoice = null;

    private ?bool $removeInstallerChoice = null;

    private ?bool $homepageChoice = null;

    /** @var list<string>|null */
    private ?array $cacheChoices = null;

    public function handle(): int
    {
        $this->toolingChoice = null;
        $this->buildAssetsChoice = null;
        $this->removeInstallerChoice = null;
        $this->homepageChoice = null;
        $this->cacheChoices = null;
        $this->reviewedPatchChoices = [];
        $this->wizardBooted = false;
        $this->freshConfirmed = false;
        $this->editAdministrator = false;
        $this->basicInstall = $this->usesBasicInstall();
        $this->suiteDownloadPackages = [];
        $this->suiteThemeKey = null;
        $this->manualInstallChanges = [];
        if ($this->basicInstall) {
            $this->input->setOption('packages', 'capell-app/admin,capell-app/frontend');
        }

        do {
            $this->restartReview = false;
            $result = $this->runWizard();
        } while ($this->restartReview);

        return $result;
    }

    #[Override]
    public function outputPlan(InstallInputData $inputData, bool $collapseWhenInteractive = false): void
    {
        $steps = InstallPlan::steps($inputData);

        $this->newLine();
        $this->line('<fg=blue;options=bold>Capell Install Plan</>');
        $this->newLine();

        if ($collapseWhenInteractive && $this->input->isInteractive() && ! $this->output->isVerbose()) {
            $this->line(__('capell-core::install.review.plan_collapsed', ['count' => $steps->count()]));
            $this->newLine();

            return;
        }

        $steps->each(function (InstallStepData $step, int $index): void {
            $this->line(sprintf('%d. %s', $index + 1, $step->label));
        });

        $this->newLine();
    }

    #[Override]
    public function buildFrontendAssets(): void
    {
        $this->line(__('capell-core::install.review.build_assets'));

        try {
            BuildInstallFrontendAssetsAction::run(new ConsoleProgressReporter($this));
            $this->info('Production build completed successfully.');
        } catch (RuntimeException $runtimeException) {
            $this->error(__('capell-core::install.recovery.build_failed'));
            $this->line($runtimeException->getMessage());

            throw $runtimeException;
        }
    }

    #[Override]
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

    #[Override]
    public function prepareApplication(InstallInputData $inputData, ProgressReporter $reporter): void
    {
        if (filled($this->option('save-profile'))) {
            SaveInstallProfileAction::run($inputData, (string) $this->option('save-profile'), $this->orchestratedBuildAssets);
        }

        if ($this->option('update-app-url')) {
            UpdateInstallAppUrlAction::run($inputData->siteUrl);
        }

        PrepareInstallApplicationAction::run(
            inputData: $inputData,
            hasFilamentAdminPanelProvider: resolve(FilamentAdminInstallPreflight::class)->hasInstalledPanelProvider(),
            interactive: $this->input->isInteractive(),
            useFreshDemoDefaults: $this->shouldUseFreshDemoDefaults(),
            reporter: $reporter,
            confirmPatch: fn (InstallPatchConfirmation $confirmation): bool => $this->reviewedPatchChoices[spl_object_id($confirmation)] ?? false,
            recordManualInstallChange: function (string $message): void {
                $this->recordManualInstallChange($message);
            },
        );
    }

    #[Override]
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

    #[Override]
    public function upgradeFilament(): void
    {
        if (! $this->getApplication()?->has('filament:upgrade')) {
            return;
        }

        $this->logInstallDebug('running filament upgrade');
        RunArtisanCommandAction::run('filament:upgrade', reporter: new ConsoleProgressReporter($this), silent: true);
        $this->logInstallDebug('filament upgrade finished');
    }

    #[Override]
    public function finalizeInstall(InstallInputData $inputData, InstallRunResultData $result): void
    {
        if ($this->input->isInteractive() && ! $this->basicInstall && ! $this->shouldUseFreshDemoDefaults()) {
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
            $handoffPath = AbsolutePath::is($handoffJson)
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

    /** @phpstan-impure Collects answers and mutates pending choices when the review is edited. */
    private function runWizard(): int
    {
        $this->resolvedSiteUrl = null;
        $this->configureHomepage = false;
        if (! $this->wizardBooted) {
            $bootExitCode = $this->bootInstallCommand();
            if ($bootExitCode !== null) {
                return $bootExitCode;
            }

            $this->wizardBooted = true;
        }

        $planOnly = $this->option('plan');
        $noSideEffects = $this->option('no-side-effects');
        [$freshInstall, $forceFreshInstall] = $this->freshInstallOptions();
        $demo = $this->shouldInstallDemoContent();
        $userEmailOption = $this->option('user');
        $userPrompter = $this->userPrompter();
        $newUser = $this->editAdministrator ? null : $userPrompter->newUserFromOptions(
            $this->option('name'),
            $this->option('email'),
            $this->option('password'),
        );
        $clearCache = $this->option('clear-cache');
        $generateSitemap = $this->option('generate-sitemap');
        $seedDatabase = (bool) $this->option('seed');
        $seedDefaultData = ! $this->option('no-seed-default-data');

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
            && ! $this->freshConfirmed
            && ! $planOnly
            && ! $this->confirmFreshInstall($forceFreshInstall)
        ) {
            $this->logInstallDebug('fresh install cancelled');

            return CommandAlias::FAILURE;
        }

        $freshInstallConfirmed = $freshInstall && ! $planOnly;
        $this->freshConfirmed = $freshInstallConfirmed;

        $this->logInstallDebug('resolved demo option', [
            'demo' => $demo,
        ]);

        if (! $this->basicInstall && ! $this->option('customise') && ! $planOnly && $this->input->isInteractive() && $this->isInstalled()
            && ! $freshInstall
            && confirm(__('capell-core::install.review.reinstall_label'), false)
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

        if ($this->basicInstall || $this->option('customise')) {
            RunInstallPreflightChecksAction::run(new InstallInputData(
                siteUrl: 'http://localhost',
                packages: [],
                languages: [],
                demoContent: false,
                cachesToClear: [],
                generateSitemap: false,
                generateStaticSite: false,
            ), new ConsoleProgressReporter($this));
        }

        $siteUrl = $this->resolveSiteUrl();
        $this->resolvedSiteUrl = $siteUrl;
        $this->applyInteractiveSuiteSelection();
        $demo = $this->shouldInstallDemoContent();
        $packages = $this->resolveSelectedPackages($demo, $freshInstall);
        if (! $packages instanceof Collection) {
            return CommandAlias::FAILURE;
        }

        $reporter = new ConsoleProgressReporter($this);

        if (! $planOnly && $packages->has('capell-app/admin')
            && ! resolve(FilamentAdminInstallPreflight::class)->hasInstalledPanelProvider()
            && $this->input->isInteractive() && ! $this->basicInstall && ! $this->shouldUseFreshDemoDefaults()
            && ! confirm(label: __('capell-core::install.review.panel_label'), default: true, hint: __('capell-core::install.review.panel_hint'))
        ) {
            $this->error('Filament must be installed before installing the Capell admin package.');

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

        $hasFrontend = $packages->filter(fn (PackageData $package): bool => $package->hasFrontendScope())->isNotEmpty()
            || $themeExtraPackages !== [];
        $this->configureHomepage = ! $planOnly && $hasFrontend && ! $this->option('install-welcome-route')
            && ! $this->basicInstall && $this->input->isInteractive() && resolve(WelcomeRouteInstaller::class)->canInstall();
        $installWelcomeRoute = $planOnly
            ? $hasFrontend && $this->option('install-welcome-route')
            : ($this->homepageChoice ??= resolve(InstallPostInstallOptionResolver::class)->resolveWelcomeRoute(
                hasFrontend: $hasFrontend,
                installWelcomeRouteOption: (bool) $this->option('install-welcome-route'),
                interactive: $this->input->isInteractive() && ! $this->basicInstall,
                welcomeRouteInstaller: resolve(WelcomeRouteInstaller::class),
            ));
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

            $planUserId = null;
            if ($userEmailOption !== null || $newUser instanceof NewUserData || ($freshInstall && $this->shouldUseFreshDemoDefaults())) {
                [$planUserId, $newUser, $userExitCode] = $userPrompter->resolveUserInput(
                    $userEmailOption,
                    $newUser,
                    $freshInstall,
                    $this->shouldUseFreshDemoDefaults(),
                );
                if ($userExitCode !== null) {
                    return $userExitCode;
                }
            }

            $planAdditionalUsers = $this->option('role-users')
                ? resolve(InstallInputFactory::class)->exampleRoleUsers((string) ($this->option('role-user-password') ?? ''))
                : [];

            $inputData = $this->buildInstallInput(
                siteUrl: $siteUrl,
                packages: $packages,
                languages: $languages,
                demo: $demo,
                siteOptions: $siteOptions,
                newUser: $newUser,
                seedDefaultData: $seedDefaultData,
                seedDatabase: $seedDatabase,
                freshInstall: $freshInstall,
                installWelcomeRoute: $installWelcomeRoute,
                developerToolingChoice: $developerToolingChoice,
                selectedThemeKey: $selectedThemeKey,
                extraPackages: array_values(array_unique([...$installTimePackageNames, ...$themeExtraPackages])),
                generateSitemap: $generateSitemap,
                userId: $planUserId,
                additionalUsers: $planAdditionalUsers,
            );

            $this->outputInstallReview(
                $inputData,
                $hasFrontend && (bool) $this->option('build-assets'),
                (bool) $this->option('remove-installer'),
                $clearCache || $freshInstall ? ['all'] : resolve(InstallCacheOptionResolver::class)->defaultKeys(
                    resolve(InstallCacheOptionResolver::class)->availableOptions(fn (string $command): bool => $this->getApplication()?->has($command) === true),
                ),
            );

            $this->info(__('capell-core::install.review.plan_hint'));

            return $this->finishPlanOnlyInstall($inputData);
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

        if (! $this->administratorCredentialsAreSafe($resolvedNewUser)) {
            return CommandAlias::FAILURE;
        }

        $this->editAdministrator = false;
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

        $developerToolingChoice = $this->toolingChoice ??= resolve(InstallPostInstallOptionResolver::class)->resolveDeveloperToolingChoice(
            developerToolingRequested: (bool) $this->option('developer-tooling'),
            skipBoostInstall: (bool) $this->option('no-boost-install'),
            developerToolingInstalled: resolve(DeveloperToolingInstallationState::class)->isInstalled(),
            interactive: $this->input->isInteractive() && ! $this->basicInstall,
            useFreshDemoDefaults: $this->shouldUseFreshDemoDefaults(),
        );
        $this->logInstallDebug('resolved developer tooling', [
            'install_developer_tooling' => $developerToolingChoice->installDeveloperTooling,
            'configure_boost_developer_tooling' => $developerToolingChoice->configureBoostDeveloperTooling,
        ]);

        $runNpmBuild = $hasFrontend && $this->option('build-assets') || ($this->buildAssetsChoice ??= resolve(InstallPostInstallOptionResolver::class)->shouldRunNpmBuild(
            hasFrontend: $hasFrontend,
            interactive: $this->input->isInteractive() && ! $this->basicInstall,
            useFreshDemoDefaults: $this->shouldUseFreshDemoDefaults(),
        ));
        $removeInstallerPackage = $this->removeInstallerChoice ??= resolve(InstallPostInstallOptionResolver::class)->shouldRemoveInstallerPackage(
            installerPackageInstalled: CapellCore::hasPackage($this->installerPackageName()),
            removeInstallerOption: (bool) $this->option('remove-installer'),
            interactive: $this->input->isInteractive() && ! $this->basicInstall,
            useFreshDemoDefaults: $this->shouldUseFreshDemoDefaults(),
        );
        $this->logInstallDebug('resolved post-install side effects', [
            'run_npm_build' => $runNpmBuild,
            'remove_installer_package' => $removeInstallerPackage,
        ]);

        $cacheOptions = resolve(InstallCacheOptionResolver::class);
        $cachesToClear = $this->cacheChoices ??= ($this->basicInstall || (! $this->input->isInteractive() && ! $clearCache && ! $freshInstall))
            ? $cacheOptions->defaultKeys($cacheOptions->availableOptions(fn (string $command): bool => $this->getApplication()?->has($command) === true))
            : array_values($cacheOptions->resolve(
                (bool) $clearCache,
                $freshInstall,
                fn (string $command): bool => $this->getApplication()?->has($command) === true,
            ));

        $inputData = $this->buildInstallInput(
            siteUrl: $siteUrl,
            packages: $packages,
            languages: $languages,
            demo: $demo,
            siteOptions: $siteOptions,
            newUser: $resolvedNewUser,
            seedDefaultData: $seedDefaultData,
            seedDatabase: $seedDatabase,
            freshInstall: $freshInstall,
            installWelcomeRoute: $installWelcomeRoute,
            developerToolingChoice: $developerToolingChoice,
            selectedThemeKey: $selectedThemeKey,
            extraPackages: array_values(array_unique([...$installTimePackageNames, ...$themeExtraPackages])),
            generateSitemap: $generateSitemap,
            userId: $userId,
            additionalUsers: $additionalUsers,
        );

        $this->outputInstallReview($inputData, $runNpmBuild, $removeInstallerPackage, $cachesToClear);
        $this->outputPlan($inputData, collapseWhenInteractive: true);

        if (! $this->reviewInstallation($inputData)) {
            $this->info(__('capell-core::install.review.cancelled'));

            return CommandAlias::SUCCESS;
        }

        if ($this->restartReview) {
            return CommandAlias::SUCCESS;
        }

        if (! resolve(FilamentAdminInstallPreflight::class)->ensureReady(
            packages: $packages,
            interactive: false,
            useFreshDemoDefaults: $this->shouldUseFreshDemoDefaults(),
            reporter: $reporter,
            writeError: function (string $message): void {
                $this->error($message);
            },
        )) {
            $this->logInstallDebug('filament admin panel check failed');

            return CommandAlias::FAILURE;
        }

        if ($this->configureHomepage) {
            resolve(InstallPostInstallOptionResolver::class)->configureWelcomeRoute(
                resolve(WelcomeRouteInstaller::class),
                $installWelcomeRoute,
                function (string $message): void {
                    $this->recordManualInstallChange($message);
                },
                function (string $message): void {
                    $this->warn($message);
                },
            );
        }

        return $this->runInstallOrchestration(
            inputData: $inputData,
            reporter: $reporter,
            seedDefaultData: $seedDefaultData,
            runNpmBuild: $runNpmBuild,
            removeInstallerPackage: $removeInstallerPackage,
            cachesToClear: $cachesToClear,
        );
    }

    /** @param array<string> $cachesToClear */
    private function outputInstallReview(InstallInputData $inputData, bool $runNpmBuild, bool $removeInstaller, array $cachesToClear): void
    {
        $patchLabels = [];
        if ($this->option('update-app-url')) {
            $patchLabels[] = __('capell-core::install.simple.app_url_change', ['url' => InstallSiteUrl::publicUrl($inputData->siteUrl)]);
        } elseif ($inputData->siteUrl !== $this->defaultSiteUrl()) {
            $patchLabels[] = __('capell-core::install.simple.app_url_keep');
        }

        if (filled($this->option('save-profile'))) {
            $patchLabels[] = __('capell-core::install.simple.profile_change', ['name' => (string) $this->option('save-profile')]);
        }

        $patchLabels = [...$patchLabels, ...($this->configureHomepage
            ? [__('capell-core::install.review.homepage_env', ['value' => $inputData->installWelcomeRoute ? 'true' : 'false'])]
            : [])];
        $hasPanel = resolve(FilamentAdminInstallPreflight::class)->hasInstalledPanelProvider();
        $creatingPanel = ! $hasPanel && in_array('capell-app/admin', $inputData->packages, true);
        $context = new InstallPatchContext(
            array_values(array_unique([...$inputData->packages, ...$inputData->extraPackages])),
            $hasPanel || $creatingPanel,
        );
        foreach (resolve(InstallPatchRegistry::class)->patchesFor($context) as $registeredPatch) {
            $status = $registeredPatch->patch->probe();
            if ($status === PatchStatus::AlreadyApplied) {
                continue;
            }

            $confirmation = $registeredPatch->confirmation;
            $accepted = true;
            if ($confirmation !== null) {
                $accepted = $this->reviewedPatchChoices[spl_object_id($confirmation)] ?? ($this->basicInstall || ! $this->input->isInteractive() || $this->shouldUseFreshDemoDefaults() || $this->option('plan')
                    ? $confirmation->default
                    : confirm(label: $confirmation->label, default: $confirmation->default, hint: $confirmation->hint ?? ''));
                $this->reviewedPatchChoices[spl_object_id($confirmation)] = $accepted;
            }

            $patchLabels[] = $registeredPatch->patch->label() . ' — ' . ($status === PatchStatus::Applicable
                ? ($accepted ? __('capell-core::install.review.apply') : __('capell-core::install.review.skip'))
                : ($creatingPanel && $confirmation !== null
                    ? ($accepted ? __('capell-core::install.review.apply_after_panel') : __('capell-core::install.review.skip'))
                    : __('capell-core::install.review.manual_patch', ['status' => $status->getLabel()])));
        }

        $review = BuildInstallReviewAction::run($inputData, $runNpmBuild, $removeInstaller, $cachesToClear, $patchLabels);
        $this->newLine();
        $this->renderInstallReview($review->items, $review->lists);
        $this->newLine();
    }

    private function usesBasicInstall(): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        foreach (['customise', 'plan', 'demo', 'fresh', 'profile', 'recommendation', 'recommendation-action', 'packages', 'package-mode', 'all-packages', 'seed', 'role-users', 'clear-cache', 'remove-installer', 'install-welcome-route', 'developer-tooling', 'production'] as $option) {
            if ($this->option($option) || $this->optionWasProvidedOnCommandLine($option)) {
                return false;
            }
        }

        return ! $this->input->hasParameterOption('--fresh');
    }

    private function reviewInstallation(InstallInputData $input): bool
    {
        if (! $this->input->isInteractive() || (! $this->basicInstall && ! $this->option('customise'))) {
            return $this->confirmInstallReview();
        }

        do {
            $choice = select(label: __('capell-core::install.simple.continue'), options: $this->basicInstall ? [
                'install' => __('capell-core::install.simple.install'),
                'customise' => __('capell-core::install.simple.customise'),
                'cancel' => __('capell-core::install.simple.cancel'),
            ] : [
                'install' => __('capell-core::install.simple.install_custom'),
                'site' => __('capell-core::install.simple.site'),
                'packages' => __('capell-core::install.simple.packages'),
                'administrator' => __('capell-core::install.simple.administrator'),
                'customise' => __('capell-core::install.simple.customise'),
                'app-url' => __('capell-core::install.simple.app_url'),
                'profile' => __('capell-core::install.simple.profile'),
                'cancel' => __('capell-core::install.simple.cancel'),
            ], default: 'install', hint: __('capell-core::install.simple.hint'));
            if ($choice === 'customise' && $this->basicInstall) {
                $choice = select(label: __('capell-core::install.simple.customise_label'), options: [
                    'site' => __('capell-core::install.simple.site'),
                    'packages' => __('capell-core::install.simple.packages'),
                    'administrator' => __('capell-core::install.simple.administrator'),
                    'app-url' => __('capell-core::install.simple.app_url'),
                    'assets' => __('capell-core::install.simple.assets'),
                    'profile' => __('capell-core::install.simple.profile'),
                    'customise' => __('capell-core::install.simple.customise_all'),
                    'back' => __('capell-core::install.simple.back'),
                ], default: 'site');
            }
        } while ($choice === 'back');

        if ($choice === 'cancel') {
            return false;
        }

        if ($choice === 'install') {
            return true;
        }

        $this->input->setOption('url', $input->siteUrl);
        $this->input->setOption('packages', implode(',', $input->packages));
        $this->input->setOption('theme', $input->selectedThemeKey);
        if ($input->newUser instanceof NewUserData) {
            $this->input->setOption('name', $input->newUser->name);
            $this->input->setOption('email', $input->newUser->email);
            $this->input->setOption('password', $input->newUser->password);
        }

        if ($input->userId !== null) {
            $userClass = config('auth.providers.users.model');
            $this->input->setOption('user', $userClass::findOrFail($input->userId)->email);
        }

        if ($choice === 'site') {
            $this->input->setOption('url', text(label: __('capell-core::install.site.url_label'), default: $input->siteUrl, required: true, validate: InstallSiteUrl::validationError(...), hint: __('capell-core::install.site.url_hint')));
        } elseif ($choice === 'app-url') {
            $this->input->setOption('update-app-url', ! $this->option('update-app-url'));
        } elseif ($choice === 'assets') {
            $this->input->setOption('build-assets', ! $this->option('build-assets'));
            $this->buildAssetsChoice = null;
        } elseif ($choice === 'profile') {
            $this->input->setOption('save-profile', text(label: __('capell-core::install.simple.profile_name'), required: true, validate: ['name' => 'regex:/^[a-z0-9][a-z0-9-]*$/']));
        } else {
            if ($choice !== 'administrator') {
                $this->basicInstall = false;
                $this->input->setOption('customise', true);
            }

            if ($choice === 'customise') {
                $this->toolingChoice = null;
                $this->buildAssetsChoice = null;
                $this->removeInstallerChoice = null;
                $this->homepageChoice = null;
                $this->cacheChoices = null;
                $this->reviewedPatchChoices = [];
            }

            if ($choice === 'packages' || $choice === 'customise') {
                $this->suiteDownloadPackages = [];
                $this->suiteThemeKey = null;
                $this->input->setOption('packages', null);
                $this->input->setOption('theme', null);
            }

            if ($choice === 'administrator') {
                $this->editAdministrator = true;
                foreach (['user', 'name', 'email', 'password'] as $option) {
                    $this->input->setOption($option, null);
                }
            }
        }

        $this->restartReview = true;

        return true;
    }

    private function confirmInstallReview(): bool
    {
        if (! $this->input->isInteractive()) {
            return true;
        }

        return confirm(
            label: __('capell-core::install.review.confirm_label'),
            default: true,
            hint: __('capell-core::install.review.confirm_hint'),
        );
    }

    /**
     * Lead with the handful of facts a newcomer needs, then list the mechanical detail as bullets.
     * The execution steps are omitted because the plan printed straight after the review lists them.
     *
     * @param  array<string, string>  $items
     * @param  array<string, list<string>>  $lists
     */
    private function renderInstallReview(array $items, array $lists): void
    {
        $none = __('capell-core::install.review.none');
        $essentialLabels = [
            __('capell-core::install.review.summary'),
            __('capell-core::install.review.site'),
            __('capell-core::install.review.database'),
            __('capell-core::install.review.packages'),
            __('capell-core::install.review.theme'),
            __('capell-core::install.review.content'),
            __('capell-core::install.review.administrator'),
        ];

        $this->line('<fg=blue;options=bold>' . __('capell-core::install.review.title') . '</>');
        $this->newLine();
        $this->line('<options=bold>' . __('capell-core::install.review.essentials_title') . '</>');

        foreach ($essentialLabels as $label) {
            if (isset($items[$label]) && $items[$label] !== $none) {
                $this->line(OutputFormatter::escape('  ' . $label . ': ' . $items[$label]));
            }
        }

        $this->newLine();
        $this->line('<options=bold>' . __('capell-core::install.review.details_title') . '</>');

        foreach (array_filter($lists) as $label => $entries) {
            $this->line(OutputFormatter::escape('  ' . $label));

            foreach ($entries as $entry) {
                $this->line(OutputFormatter::escape('    • ' . $entry));
            }
        }
    }

    private function finishPlanOnlyInstall(InstallInputData $inputData): int
    {
        $this->outputPlan($inputData);
        $this->logInstallDebug('finished plan-only command');

        return CommandAlias::SUCCESS;
    }

    /** @param array<string> $cachesToClear */
    private function runInstallOrchestration(
        InstallInputData $inputData,
        ProgressReporter $reporter,
        bool $seedDefaultData,
        bool $runNpmBuild,
        bool $removeInstallerPackage,
        array $cachesToClear,
    ): int {
        $this->orchestratedSeedDefaultData = $seedDefaultData;
        $this->orchestratedBuildAssets = $runNpmBuild;

        try {
            $this->logInstallDebug('running install orchestration');
            OrchestrateInstallAction::run(
                $inputData,
                new InstallOrchestrationData(
                    outputPlan: false,
                    runNpmBuild: $runNpmBuild,
                    removeInstaller: $removeInstallerPackage,
                    cachesToClear: $cachesToClear,
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

    /**
     * @param  Collection<string, PackageData>  $packages
     * @param  array<string>  $languages
     * @param  array<string>  $siteOptions
     * @param  array<NewUserData>  $additionalUsers
     * @param  array<string>  $extraPackages
     */
    private function buildInstallInput(
        string $siteUrl,
        Collection $packages,
        array $languages,
        bool $demo,
        array $siteOptions,
        ?NewUserData $newUser,
        bool $seedDefaultData,
        bool $seedDatabase,
        bool $freshInstall,
        bool $installWelcomeRoute,
        DeveloperToolingChoiceData $developerToolingChoice,
        ?string $selectedThemeKey,
        array $extraPackages,
        bool $generateSitemap,
        ?int $userId = null,
        array $additionalUsers = [],
    ): InstallInputData {
        return resolve(InstallInputFactory::class)->fromResolvedConsoleInput(
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
            newUser: $newUser,
            seedDefaultData: $seedDefaultData,
            seedDatabase: $seedDatabase,
            freshInstall: $freshInstall,
            installWelcomeRoute: $installWelcomeRoute,
            installDeveloperTooling: $developerToolingChoice->installDeveloperTooling,
            configureBoostDeveloperTooling: $developerToolingChoice->configureBoostDeveloperTooling,
            additionalUsers: $additionalUsers,
            selectedThemeKey: resolve(ThemePackageCandidates::class)->inputThemeKey($selectedThemeKey),
            extraPackages: $extraPackages,
        );
    }

    private function bootInstallCommand(): ?int
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

        $recommendationExitCode = $this->applyInstallRecommendationDefaults();
        if ($recommendationExitCode !== null) {
            return $recommendationExitCode;
        }

        $newUser = $this->userPrompter()->newUserFromOptions($this->option('name'), $this->option('email'), $this->option('password'));
        if (! $newUser instanceof NewUserData && $this->shouldUseFreshDemoDefaults()) {
            $newUser = FreshInstallDefaults::adminUser();
        }

        return $this->administratorCredentialsAreSafe($newUser) ? null : CommandAlias::FAILURE;
    }

    private function resolveSiteUrl(): string
    {
        $siteUrl = $this->option('url');
        if ($siteUrl === null && (! $this->input->isInteractive() || $this->option('plan'))) {
            $siteUrl = $this->defaultSiteUrl();
            $this->logInstallDebug('using default site url', [
                'site_url' => $siteUrl,
            ]);
        }

        if ($siteUrl === null || $siteUrl === '') {
            $this->logInstallDebug('prompting for site url');
            $this->requireInteractiveOrFail('Site URL', 'Pass --url=<url>.');
            $siteUrl = text(
                label: __('capell-core::install.site.url_label'),
                default: $this->defaultSiteUrl(),
                required: true,
                validate: InstallSiteUrl::validationError(...),
                hint: __('capell-core::install.site.url_hint'),
            );
        }

        $siteUrl = trim($siteUrl);
        $validationError = InstallSiteUrl::validationError($siteUrl);
        throw_if($validationError !== null, InvalidArgumentException::class, $validationError ?? '');

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
        return resolve(AdminPanelUrlResolver::class)->resolve();
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

        if ((bool) $this->option('no-interaction')) {
            $this->warn('Fresh install requires confirmation. Use --fresh=force for non-interactive runs.');

            return false;
        }

        if (confirm('Warning: this will delete all your data. Are you sure?', false)) {
            return true;
        }

        $this->warn('Fresh install cancelled.');

        return false;
    }

    private function administratorCredentialsAreSafe(?NewUserData $user): bool
    {
        if (CanCreateInstallAdministratorAction::run($user, (bool) $this->option('allow-demo-credentials'))) {
            return true;
        }

        $this->error(__('capell-core::install.demo.production_credentials_refused'));

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
            $available = fn (string $name): bool => CapellCore::hasPackage($name) || TrustedCorePackages::contains($name);
            $this->suiteDownloadPackages = array_values(array_filter($this->installProfile->packages, fn (string $name): bool => ! $available($name)));
            $this->input->setOption('packages', implode(',', array_filter($this->installProfile->packages, $available)));
        }

        if ($this->installProfile->theme !== null && ! $this->optionWasProvidedOnCommandLine('theme')) {
            $this->input->setOption('theme', $this->installProfile->theme);
        }

        if ($this->installProfile->languages !== [] && ! $this->optionWasProvidedOnCommandLine('languages')) {
            $this->input->setOption('languages', implode(',', $this->installProfile->languages));
        }

        if ($this->installProfile->seedDefaultData !== null && ! $this->optionWasProvidedOnCommandLine('no-seed-default-data')) {
            $this->input->setOption('no-seed-default-data', ! $this->installProfile->seedDefaultData);
        }

        if ($this->installProfile->seedDatabase !== null && ! $this->optionWasProvidedOnCommandLine('seed')) {
            $this->input->setOption('seed', $this->installProfile->seedDatabase);
        }

        if ($this->installProfile->buildAssets !== null && ! $this->optionWasProvidedOnCommandLine('build-assets')) {
            $this->input->setOption('build-assets', $this->installProfile->buildAssets);
        }

        if ($this->installProfile->siteUrl !== null && ! $this->optionWasProvidedOnCommandLine('url')) {
            $this->input->setOption('url', $this->installProfile->siteUrl);
        }

        if ($this->installProfile->sites !== [] && ! $this->optionWasProvidedOnCommandLine('sites')) {
            $this->input->setOption('sites', implode(',', $this->installProfile->sites));
        }

        if ($this->installProfile->demo !== null && ! $this->optionWasProvidedOnCommandLine('demo')) {
            $this->input->setOption('demo', $this->installProfile->demo);
        }
    }

    private function applyInstallRecommendationDefaults(): ?int
    {
        $key = $this->option('recommendation');
        $action = $this->option('recommendation-action');

        if ((! is_string($key) || trim($key) === '') && (! is_string($action) || trim($action) === '')) {
            return null;
        }

        $actionValue = is_string($action) && trim($action) !== ''
            ? trim($action)
            : 'confirm';
        if (! in_array($actionValue, ['select', 'confirm', 'custom', 'skip'], true)) {
            $this->error('The --recommendation-action option must be select, confirm, custom, or skip.');

            return CommandAlias::FAILURE;
        }

        if ($actionValue === 'skip') {
            if (! $this->optionWasProvidedOnCommandLine('packages') && ! $this->optionWasProvidedOnCommandLine('package-mode')) {
                $this->input->setOption('packages', '');
            }

            return null;
        }

        if ($actionValue === 'custom') {
            if (! $this->optionWasProvidedOnCommandLine('packages')) {
                $this->error('Custom recommendations require --packages=<package-name,package-name>.');

                return CommandAlias::FAILURE;
            }

            return null;
        }

        if (! is_string($key) || trim($key) === '') {
            $this->error('Pass --recommendation=<key> for a selected or confirmed recommendation.');

            return CommandAlias::FAILURE;
        }

        $recommendation = resolve(InstallRecommendationRepository::class)->find(trim($key));
        if ($recommendation === null) {
            $this->error(sprintf('Unknown install recommendation [%s].', $key));

            return CommandAlias::FAILURE;
        }

        if (! $this->optionWasProvidedOnCommandLine('packages')) {
            $this->input->setOption('packages', implode(',', $recommendation->packages));
        }

        if ($recommendation->theme !== null && ! $this->optionWasProvidedOnCommandLine('theme')) {
            $this->input->setOption('theme', $recommendation->theme);
        }

        if ($recommendation->demo !== null && ! $this->optionWasProvidedOnCommandLine('demo')) {
            $this->input->setOption('demo', $recommendation->demo);
        }

        return null;
    }

    /**
     * Ask "What are you building?" only when nothing on the command line already decided the package list.
     * Unregistered, non-core extensions cannot go through --packages, so they join the Composer downloads.
     */
    private function applyInteractiveSuiteSelection(): void
    {
        $alreadyDecided = $this->basicInstall || filled($this->option('packages')) || $this->installProfile instanceof InstallProfileData
            || $this->option('plan')
            || $this->shouldUseFreshDemoDefaults()
            || filled($this->option('recommendation'))
            || filled($this->option('recommendation-action'))
            || $this->optionWasProvidedOnCommandLine('packages')
            || $this->optionWasProvidedOnCommandLine('package-mode')
            || $this->optionWasProvidedOnCommandLine('all-packages');

        if ($alreadyDecided || ! $this->input->isInteractive()) {
            return;
        }

        [$freshInstall] = $this->freshInstallOptions();
        $selection = resolve(InstallSuitePrompter::class)->prompt($freshInstall, $this->resolvedSiteUrl);
        if ($selection === null) {
            return;
        }

        $installable = fn (string $packageName): bool => CapellCore::hasPackage($packageName)
            || TrustedCorePackages::contains($packageName);

        $this->suiteDownloadPackages = array_values(array_filter(
            $selection->packages,
            fn (string $packageName): bool => ! $installable($packageName),
        ));
        $this->input->setOption('packages', implode(',', array_values(array_filter($selection->packages, $installable))));

        $this->suiteThemeKey = $selection->theme;

        // A fresh install with --demo takes the unattended known-credentials path, so a suite
        // must never opt a fresh install into it; sample content stays an explicit --demo choice there.
        if ($selection->demo !== null && ! $freshInstall && ! $this->optionWasProvidedOnCommandLine('demo')) {
            $this->input->setOption('demo', $selection->demo);
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
        return array_values(array_unique([
            ...$this->packageSetComposer()->installTimePackageNames(
                selectedPackageNames: $this->parseListOption('packages') ?? [],
                packageMode: $this->option('package-mode'),
                allPackages: (bool) $this->option('all-packages'),
                useFreshDemoPackageDefaults: $this->shouldUseFreshDemoPackageDefaults(),
            ),
            ...$this->suiteDownloadPackages,
        ]));
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
            interactive: $this->input->isInteractive() && ! $this->basicInstall,
            useFreshDemoDefaults: $this->shouldUseFreshDemoDefaults(),
            writeError: function (string $message): void {
                $this->error($message);
            },
            preferredThemeKey: $this->suiteThemeKey,
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
