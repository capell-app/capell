<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RunInstallAction;
use Capell\Core\Actions\Install\RunInstallStepAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\NewUserData;
use Capell\Core\Enums\PackageScopeEnum;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Jobs\RunCapellInstallJob;
use Capell\Core\Models\Site;
use Capell\Core\Support\Install\DeveloperToolingInstallationState;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Plugins\PluginPackagesFetcher;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Installer\Actions\BuildInstallerPageDataAction;
use Capell\Installer\Support\InstallerSessionRepository;
use Capell\Installer\Support\Preflight\InstallerPreflight;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Composer\InstalledVersions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withSession;

use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 4) . '/tests/Support/InstallFilesystemLock.php';

uses(CreatesAdminUser::class)->group('installer');

function installPostPayload(array $overrides = []): array
{
    return array_merge([
        'site_url' => 'https://example.com',
        'language' => 'en',
        'package_selection_mode' => 'custom',
        'packages' => [],
        'new_user_name' => 'Admin',
        'new_user_email' => 'admin@example.com',
        'new_user_password' => 'password123',
        'seed_default_data' => '1',
        'demo_content' => null,
        'install_filament_panel' => null,
        'generate_sitemap' => null,
        'generate_static_site' => null,
        'rebuild_resources' => null,
        'install_developer_tooling' => null,
        'configure_boost_developer_tooling' => null,
        'run_as_job' => null,
    ], $overrides);
}

function installerAccessSessionData(string $installId): array
{
    return [sprintf('capell.install.%s.access', $installId) => true];
}

function installerSessionSuffixes(): array
{
    return ['input', 'plan', 'status', 'output', 'user_id', 'current_step', 'completed_steps', 'preflight', 'success'];
}

function bindSetupPluginPackagesFetcher(Collection $remote): void
{
    app()->bind(PluginPackagesFetcher::class, fn (): PluginPackagesFetcher => new class($remote) extends PluginPackagesFetcher
    {
        public function __construct(private readonly Collection $remote) {}

        public function fetch(bool $force = false): Collection
        {
            return $this->remote;
        }

        public function getCached(): Collection
        {
            return $this->remote;
        }
    });
}

function bindInstallerDeveloperToolingInstallationState(bool $installed): void
{
    app()->instance(DeveloperToolingInstallationState::class, new class($installed) extends DeveloperToolingInstallationState
    {
        public function __construct(private readonly bool $installed) {}

        public function isInstalled(): bool
        {
            return $this->installed;
        }
    });
}

beforeEach(function (): void {
    bindInstallerDeveloperToolingInstallationState(false);
});

function bindSetupRemoveProcessFactory(): void
{
    preserveTestbenchPackageManifestFilesDuringPackageRemoval();

    $process = Mockery::mock(Process::class);
    $process
        ->shouldReceive('setEnv')
        ->with(Mockery::on(fn (array $environment): bool => ($environment['GIT_CONFIG_KEY_0'] ?? null) === 'safe.directory'
            && ($environment['GIT_CONFIG_VALUE_0'] ?? null) === '*'))
        ->andReturnSelf();
    $process
        ->shouldReceive('setTimeout')
        ->with(300)
        ->andReturnSelf();
    $process
        ->shouldReceive('run')
        ->once()
        ->andReturn(0);
    $process
        ->shouldReceive('getErrorOutput')
        ->andReturn('');
    $process
        ->shouldReceive('getOutput')
        ->andReturn('Package capell-app/installer removed');
    $process
        ->shouldReceive('isSuccessful')
        ->andReturnTrue();

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory
        ->shouldReceive('make')
        ->once()
        ->with(
            Mockery::on(fn (array|string $command): bool => $command === ['composer', 'remove', 'capell-app/installer', '--no-interaction', '--no-scripts']),
            Mockery::type('string'),
        )
        ->andReturn($process);

    app()->instance(ProcessFactoryInterface::class, $factory);
}

function writeSetupFilamentUserModel(): void
{
    acquireCapellInstallFilesystemLock();

    $userModelPath = base_path('app/Models/User.php');

    if (! is_dir(dirname($userModelPath))) {
        mkdir(dirname($userModelPath), 0755, true);
    }

    file_put_contents($userModelPath, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
PHP);
}

afterEach(function (): void {
    releaseCapellInstallFilesystemLock();
});

// ─── Access guard ────────────────────────────────────────────────────────────

it('shows the installer when capell core is not yet recorded as installed', function (): void {
    get(route('capell-installer.show'))
        ->assertOk()
        ->assertSee(__('capell-installer::installer.heading'))
        ->assertSee('aria-label="Capell"', false)
        ->assertSee('id="install-form"', false)
        ->assertDontSee(__('capell-installer::installer.already_installed_message'));
});

it('passes the expected installer page data to the install view', function (): void {
    get(route('capell-installer.show'))
        ->assertOk()
        ->assertViewHas('installId')
        ->assertViewHas('installStatus', 'idle')
        ->assertViewHas('cancelUrl')
        ->assertViewHasAll([
            'packages',
            'corePackages',
            'installedPackages',
            'downloadablePackages',
            'preflightReport',
            'languages',
            'customLanguageSuggestions',
            'existingUsers',
            'defaultSiteUrl',
            'defaultLocale',
            'allPackageNames',
            'defaultPackageNames',
            'installableExtraPackageNames',
            'requirementsMap',
            'themeOptions',
            'themePackageNames',
            'installedThemeKeys',
            'defaultThemeKey',
            'showThemeSelector',
            'showDemoToggle',
            'installId',
            'installStatus',
            'cancelUrl',
            'capellAlreadyInstalled',
            'canReinstall',
            'showFilamentPanelToggle',
            'showWelcomeRouteToggle',
            'showRoleUsersToggle',
            'developerToolingInstalled',
            'defaultAdminUser',
        ]);
});

it('shows active browser install state without clearing the active lock', function (string $status): void {
    $installId = '11111111-1111-4111-a111-111111111111';
    Cache::put('capell.install.lock', ['installId' => $installId]);
    Cache::put(sprintf('capell.install.%s.status', $installId), $status);

    expect(resolve(InstallerSessionRepository::class)->hasActiveInstallLock())->toBeTrue();
    expect(BuildInstallerPageDataAction::run(false, false)->toViewData()['installId'])->toBe($installId);

    withSession(installerAccessSessionData($installId))
        ->get(route('capell-installer.show'))
        ->assertOk()
        ->assertViewHas('installId', $installId)
        ->assertViewHas('installStatus', $status)
        ->assertViewHas('cancelUrl', route('capell-installer.cancel', ['installId' => $installId]));

    expect(Cache::get('capell.install.lock'))->toBe(['installId' => $installId]);
})->with(['pending', 'queued', 'running']);

it('hides active browser install details without install session access', function (): void {
    $installId = '11111111-1111-4111-a111-333333333333';
    Cache::put('capell.install.lock', ['installId' => $installId]);
    Cache::put(sprintf('capell.install.%s.status', $installId), 'running');

    get(route('capell-installer.show'))
        ->assertOk()
        ->assertViewHas('installId')
        ->assertViewHas('installStatus', 'idle')
        ->assertViewHas('cancelUrl');

    expect(Cache::get('capell.install.lock'))->toBe(['installId' => $installId]);
});

it('cleans stale installer locks from the installer page data', function (): void {
    $installId = '11111111-1111-4111-a111-222222222222';
    Cache::put('capell.install.lock', ['installId' => $installId]);
    Cache::put(sprintf('capell.install.%s.status', $installId), 'failed');

    get(route('capell-installer.show'))
        ->assertOk()
        ->assertViewHas('installId')
        ->assertViewHas('installStatus', 'idle')
        ->assertViewHas('cancelUrl');

    expect(Cache::get('capell.install.lock'))->toBeNull();
});

it('serves the installer page with fresh session-only cache headers', function (): void {
    $response = get(route('capell-installer.show'))
        ->assertOk()
        ->assertHeader('Pragma', 'no-cache')
        ->assertHeader('Expires', '0');

    $headers = $response->baseResponse->headers;

    expect($headers->getCacheControlDirective('no-store'))->toBeTrue()
        ->and($headers->getCacheControlDirective('no-cache'))->toBeTrue()
        ->and($headers->getCacheControlDirective('must-revalidate'))->toBeTrue()
        ->and($headers->getCacheControlDirective('private'))->toBeTrue()
        ->and($headers->getCacheControlDirective('max-age'))->toBe('0');
});

it('uses a self-contained capell favicon on the installer page', function (): void {
    get(route('capell-installer.show'))
        ->assertOk()
        ->assertSee('rel="icon"', false)
        ->assertSee('type="image/svg+xml"', false)
        ->assertSee('data:image/svg+xml;base64,', false);
});

it('shows a bundled Capell logo on the installer page before admin is installed', function (): void {
    get(route('capell-installer.show'))
        ->assertOk()
        ->assertSee('aria-label="Capell"', false)
        ->assertSee('capell-installer-logo-mark', false)
        ->assertSee('capell-installer-logo-text', false);
});

it('shows a direct web installer package checklist', function (): void {
    $html = get(route('capell-installer.show'))
        ->assertOk()
        ->assertSee('name="package_selection_mode"', false)
        ->assertSee('value="custom"', false)
        ->assertSee('name="packages[]"', false)
        ->assertSee('name="theme"', false)
        ->assertDontSee('All core packages (Admin + Frontend)')
        ->assertDontSee('All packages')
        ->assertDontSee('Custom package selection')
        ->content();

    expect($html)
        ->toContain('value="default"')
        ->not->toMatch('/name="packages\\[\\]"[^>]*value="capell-app\\/foundation-theme"/s');
});

it('accepts package selection mode from web installer submissions', function (): void {
    bindSetupPluginPackagesFetcher(Collection::make([
        ['name' => 'capell-app/remote-extension', 'description' => 'Remote extension'],
    ]));
    Cache::put('capell.installer.package_installable.' . hash('sha256', 'capell-app/remote-extension'), true);

    $spy = RunInstallAction::spy();

    post(route('capell-installer.store'), installPostPayload([
        'package_selection_mode' => 'custom',
        'extra_packages' => ['capell-app/remote-extension'],
    ]))->assertRedirect();

    $spy->shouldHaveReceived('handle')
        ->withArgs(function (InstallInputData $inputData): bool {
            expect($inputData->extraPackages)->toContain('capell-app/remote-extension');

            return true;
        })
        ->once();
});

it('allows custom web package selection to submit no selected packages', function (): void {
    $spy = RunInstallAction::spy();

    post(route('capell-installer.store'), installPostPayload([
        'package_selection_mode' => 'custom',
        'packages' => [],
        'theme' => 'none',
    ]))->assertRedirect();

    $spy->shouldHaveReceived('handle')
        ->withArgs(function (InstallInputData $inputData): bool {
            expect($inputData->packages)->toBe([])
                ->and($inputData->extraPackages)->toBe([]);

            return true;
        })
        ->once();
});

it('rejects extra packages that are not available through the installer package catalogue', function (): void {
    bindSetupPluginPackagesFetcher(Collection::make([
        ['name' => 'vendor/available', 'description' => 'Available extension'],
    ]));
    Cache::put('capell.installer.package_installable.' . hash('sha256', 'vendor/available'), true);

    post(route('capell-installer.store'), installPostPayload([
        'extra_packages' => ['vendor/unlisted'],
    ]))->assertSessionHasErrors('extra_packages.0');
});

it('rejects unknown web installer package modes', function (): void {
    post(route('capell-installer.store'), installPostPayload([
        'package_selection_mode' => 'everything',
    ]))->assertSessionHasErrors('package_selection_mode');
});

it('shows the capell logo on the progress page', function (): void {
    $installId = '22222222-2222-4222-a222-111111111111';
    Cache::put(sprintf('capell.install.%s.status', $installId), 'running');

    withSession(installerAccessSessionData($installId))
        ->get(route('capell-installer.progress', ['installId' => $installId]))
        ->assertOk()
        ->assertSee(__('capell-installer::installer.progress_heading'))
        ->assertSee('aria-label="Capell"', false)
        ->assertSee('[hidden]', false)
        ->assertSee('class="progress-report-link"', false)
        ->assertSee(__('capell-installer::installer.download_report'));
});

it('places the progress page download form above the log', function (): void {
    $content = file_get_contents(dirname(__DIR__, 2) . '/resources/views/progress.blade.php');

    expect($content)
        ->toContain('class="progress-report-link"')
        ->toContain('method="GET"')
        ->toContain('target="_blank"')
        ->toContain('download="{{ $reportDownloadFilename }}"')
        ->and(strpos($content, 'id="report-link"'))->toBeLessThan(strpos($content, 'id="log"'))
        ->and($content)->not->toContain("class=\"button secondary\"\n                    href=\"{{ \$reportUrl }}\"\n                    id=\"report-link\"");
});

it('keeps the progress page restart label statement syntactically separated', function (): void {
    $content = file_get_contents(dirname(__DIR__, 2) . '/resources/views/progress.blade.php');

    expect($content)
        ->toContain("var restartInstallLabel = @json(__('capell-installer::installer.restart_install'));")
        ->not->toContain("@json(__('capell-installer::installer.restart_install'))\n                var stopped");
});

it('renders the installer configuration and script modules in dependency order', function (): void {
    $html = get(route('capell-installer.show'))
        ->assertOk()
        ->assertSee('id="capell-installer-config"', false)
        ->content();
    $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/install.blade.php');
    $modules = [
        'install/support.js',
        'install/wizard.js',
        'install/packages.js',
        'install/form-options.js',
        'install/progress.js',
        'install/csrf.js',
        'install/runner.js',
        'install.js',
    ];

    expect($view)
        ->toContain('type="application/json"')
        ->toContain('id="capell-installer-config"')
        ->toContain('Js::encode($installerConfig)')
        ->not->toContain('json_encode($installerConfig')
        ->toContain('data-submit-label')
        ->toContain('class="submit-arrow"')
        ->toContain('installPackageLabel')
        ->toContain('installPackagesLabel')
        ->toContain('installingPackageLabel')
        ->toContain('installingPackagesLabel')
        ->not->toContain('@json');

    $previousPosition = strpos($html, 'id="capell-installer-config"');

    foreach ($modules as $module) {
        $position = strpos($html, 'data-installer-module="' . $module . '"');

        expect($position)
            ->not->toBeFalse()
            ->toBeGreaterThan($previousPosition);

        $previousPosition = $position;
    }
});

it('does not render a separate review step', function (): void {
    $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/install.blade.php');

    expect($view)
        ->not->toContain('data-step-trigger="review"')
        ->not->toContain('data-installer-step="review"');
});

it('animates installer step navigation with distinct back and continue pacing', function (): void {
    $styles = file_get_contents(dirname(__DIR__, 2) . '/resources/css/installer.css');

    expect($styles)->toContain('@media (prefers-reduced-motion: reduce)');
});

it('keeps preflight panels free of decorative gradients', function (): void {
    $content = file_get_contents(dirname(__DIR__, 2) . '/resources/css/installer.css');

    preg_match_all('/\\.preflight-panel\\s*\\{[^}]*\\}/', $content, $preflightPanelMatches);

    expect($preflightPanelMatches[0])
        ->not->toBeEmpty()
        ->each->not->toContain('gradient(');
});

it('renders preflight checks as a high-density utility panel', function (): void {
    $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/install.blade.php');
    $styles = file_get_contents(dirname(__DIR__, 2) . '/resources/css/installer.css');

    expect($view)
        ->toContain('preflight_passing_summary')
        ->not->toContain('preflight-utility-footer')
        ->not->toContain('preflight_footer_')
        ->and($styles)
        ->not->toContain('.preflight-utility-footer');
});

it('keeps the installer footer on a Capell brand colour', function (): void {
    $content = file_get_contents(dirname(__DIR__, 2) . '/resources/css/installer.css');

    expect($content)
        ->toContain('background: var(--brand-panel);')
        ->not->toContain('background: rgba(249, 248, 243, 0.94);');
});

it('does not render the removed installer nav', function (): void {
    $content = file_get_contents(dirname(__DIR__, 2) . '/resources/views/install.blade.php');

    expect($content)->not->toContain('installer-nav');
});

it('renders AI Agent Bridge developer tooling separately from downloadable packages', function (): void {
    $content = file_get_contents(dirname(__DIR__, 2) . '/resources/views/install.blade.php');

    expect($content)
        ->toContain('name="install_developer_tooling"')
        ->toContain('name="configure_boost_developer_tooling"')
        ->toContain('data-developer-tooling-checkbox')
        ->toContain('data-boost-tooling-checkbox')
        ->toContain('section_developer_tooling')
        ->toContain('extra_packages[]')
        ->not->toContain("old('install_developer_tooling', '1')")
        ->not->toContain("old('configure_boost_developer_tooling', true)");
});

it('hides AI Agent Bridge developer tooling when it is already installed', function (): void {
    bindInstallerDeveloperToolingInstallationState(true);

    get(route('capell-installer.show'))
        ->assertOk()
        ->assertDontSee('name="install_developer_tooling"', false)
        ->assertDontSee('name="configure_boost_developer_tooling"', false)
        ->assertDontSee(__('capell-installer::installer.option_install_developer_tooling'))
        ->assertDontSee(__('capell-installer::installer.option_configure_boost_developer_tooling'))
        ->assertDontSee(__('capell-installer::installer.section_developer_tooling'));
});

it('does not rerun Boost install when AI Agent Bridge developer tooling is already installed', function (): void {
    bindInstallerDeveloperToolingInstallationState(true);
    $spy = RunInstallAction::spy();

    post(route('capell-installer.store'), installPostPayload())->assertRedirect();

    $spy->shouldHaveReceived('handle')
        ->withArgs(function (InstallInputData $inputData): bool {
            expect($inputData->installDeveloperTooling)->toBeTrue()
                ->and($inputData->configureBoostDeveloperTooling)->toBeFalse();

            return true;
        })
        ->once();
});

it('renders a post-install launchpad for first admin onboarding', function (): void {
    $installId = '6b1af98b-bfd5-46da-9aa8-765d94f17d7c';
    $successView = file_get_contents(dirname(__DIR__, 2) . '/resources/views/success.blade.php');
    Cache::put(sprintf('capell.install.%s.status', $installId), 'complete');
    Cache::put(sprintf('capell.install.%s.success', $installId), []);

    expect($successView)
        ->toContain('completion-security-panel')
        ->toContain('remove_installer_recommendation_body')
        ->toContain('launchpad-account-summary')
        ->toContain('launchpad_primary_admin')
        ->toContain('launchpad_checklist_title')
        ->toContain('launchpad_check_roles')
        ->and(strpos($successView, 'data-remove-installer-form'))
        ->toBeLessThan(strpos($successView, 'completion-success-panel'));

    withSession(installerAccessSessionData($installId))
        ->get(route('capell-installer.success', ['installId' => $installId]))
        ->assertOk()
        ->assertSee(__('capell-installer::installer.remove_installer_recommendation_body'))
        ->assertSee(__('capell-installer::installer.launchpad_heading'))
        ->assertSee(__('capell-installer::installer.launchpad_checklist_title'))
        ->assertDontSee('Admin -&gt; Extensions', false);
});

it('renders mobile-first progress loading, failure, and technical log regions', function (): void {
    $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/install.blade.php');
    $styles = file_get_contents(dirname(__DIR__, 2) . '/resources/css/installer.css');

    expect($view)
        ->toContain('id="progress-loader"')
        ->toContain('id="current-step-strip"')
        ->toContain('id="failure-panel"')
        ->toContain('id="technical-log-panel"')
        ->toContain('id="technical-log-panel"' . PHP_EOL . '                    open')
        ->and($styles)->toContain('.progress-steps-summary')
        ->toContain('.progress-steps-timeline')
        ->toContain('.progress-step-select')
        ->not->toContain('scroll-snap-type: x mandatory')
        ->toContain('installer-loading-beam')
        ->toContain('current-step-spinner')
        ->toContain('.button.is-loading:disabled')
        ->toContain('.submit.is-loading .submit-arrow');
});

it('places the diagnostic download form above the console output', function (): void {
    $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/install.blade.php');

    expect($view)
        ->toContain('class="progress-report-link"')
        ->toContain('method="GET"')
        ->toContain('target="_blank"')
        ->toContain('data-download-filename')
        ->toContain('data-report-download-button')
        ->toContain('technical-log-actions')
        ->toContain('technical-log-chevron')
        ->and(strpos($view, 'id="report-link"'))->toBeLessThan(strpos($view, 'id="log"'))
        ->and($view)->not->toContain('class="button secondary"' . PHP_EOL . '                        href="#"' . PHP_EOL . '                        id="report-link"');
});

it('summarises web server timeout pages instead of showing raw html', function (): void {
    $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/install.blade.php');

    expect($view)
        ->toContain('serverTimeoutError')
        ->toContain('server_timeout_error');
});

it('renders preflight feedback and removes post-install operational toggles', function (): void {
    $response = get(route('capell-installer.show'))
        ->assertOk()
        ->assertSee(__('capell-installer::installer.section_preflight'))
        ->assertDontSee('data-flow-step="installing"', false)
        ->assertSee(__('capell-installer::installer.environment_os'))
        ->assertSee('multi-step-progress')
        ->assertSee('progressCompletedSteps')
        ->assertDontSee('Generate XML sitemaps')
        ->assertDontSee('Generate static HTML cache')
        ->assertDontSee('Required by another selected package');

    if (InstalledVersions::isInstalled('filament/filament')) {
        $response->assertSee(__('capell-installer::installer.environment_filament'));
    }

    if (InstalledVersions::isInstalled('livewire/livewire')) {
        $response->assertSee(__('capell-installer::installer.environment_livewire'));
    }
});

it('uses the left rail step navigation as the installer progress indicator', function (): void {
    $styles = file_get_contents(dirname(__DIR__, 2) . '/resources/css/installer.css');
    $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/install.blade.php');

    expect($styles)
        ->toContain('counter-reset: installer-step')
        ->toContain('content: counter(installer-step)')
        ->not->toContain('.installer-tabs')
        ->and($view)
        ->toContain('class="installer-step active"')
        ->not->toContain('class="installer-tabs"');
});

it('combines site setup and admin login into the site step', function (): void {
    $html = get(route('capell-installer.show'))
        ->assertOk()
        ->assertSee('Site URL')
        ->assertSee('Set up the first site for this Capell installation.')
        ->assertSee('Set up admin login')
        ->content();

    expect($html)
        ->toContain('data-installer-step="site"')
        ->toContain('class="site-setup-grid"')
        ->toContain('name="new_user_email"')
        ->toContain('name="custom_language_code"')
        ->toContain('Add another language...')
        ->not->toContain('data-step-trigger="admin"')
        ->not->toContain('data-installer-step="admin"');
});

it('renders the full installer language list', function (): void {
    $html = get(route('capell-installer.show'))
        ->assertOk()
        ->content();

    expect($html)
        ->toContain('value="cy"')
        ->toContain('Welsh')
        ->toContain('value="ga"')
        ->toContain('Irish')
        ->toContain('value="zu"')
        ->toContain('Zulu');
});

it('passes a custom installer language code through to the install input', function (): void {
    Queue::fake();
    $spy = RunInstallAction::spy();

    post(route('capell-installer.store'), installPostPayload([
        'language' => '__custom',
        'custom_language_code' => 'cy',
    ]))->assertRedirect();

    $spy->shouldHaveReceived('handle')->once()->withArgs(
        fn (InstallInputData $input, ProgressReporter $reporter): bool => $input->languages === ['cy'],
    );
});

it('renders npm rebuild resources as an install option', function (): void {
    get(route('capell-installer.show'))
        ->assertOk()
        ->assertSee(__('capell-installer::installer.option_rebuild_resources'))
        ->assertSee(__('capell-installer::installer.option_rebuild_resources_help'));
});

it('renders a preflight cache warning without reading a missing database cache table', function (): void {
    config([
        'cache.default' => 'database',
        'cache.stores.database.table' => 'missing_installer_cache_table',
    ]);

    get(route('capell-installer.show'))
        ->assertOk()
        ->assertSee('CACHE_STORE=database')
        ->assertSee('CACHE_STORE=file');
});

it('renders admin panel changes as a grouped automatic or manual choice', function (): void {
    $html = get(route('capell-installer.show'))
        ->assertOk()
        ->content();

    expect($html)
        ->toContain('class="admin-panel-changes"')
        ->toContain('data-admin-panel-changes')
        ->toContain('Admin panel changes')
        ->toContain('Auto apply changes')
        ->toContain('Manually apply after setup')
        ->toContain('name="admin_panel_changes_mode"')
        ->toMatch('/name="admin_panel_changes_mode"[^>]*value="auto"[^>]*checked/s')
        ->toContain('data-admin-panel-manual-help')
        ->not->toContain('Panel provider, class, or ID')
        ->not->toContain('Schema discovery')
        ->not->toContain('name="admin_panel"')
        ->not->toContain('name="admin_discover_schemas"');
});

it('shows the admin installer docs link with the manual admin panel changes option', function (): void {
    get(route('capell-installer.show'))
        ->assertOk()
        ->assertSee('href="https://docs.capell.app/admin-setup/"', false)
        ->assertSee('Manual setup guide')
        ->assertSee('Adds CapellAdminPlugin::make() to your panel and discovers Capell schemas from your resources directory.')
        ->assertSee('Adds FilamentColorEnum::colors() so Capell resources use the standard admin palette.')
        ->assertSee('Adds CapellAdmin::getWidgets() to the panel dashboard.')
        ->assertSee('Adds CapellAdmin::getNavigationItems() and CapellAdmin::getNavigationGroups().');
});

it('hides the admin panel changes fieldset when the admin package is not selected', function (): void {
    $template = file_get_contents(dirname(__DIR__, 2) . '/resources/views/install.blade.php');

    $html = withSession([
        '_old_input' => [
            'packages' => [],
        ],
    ])->get(route('capell-installer.show'))
        ->assertOk()
        ->content();

    expect($html)
        ->toContain('data-admin-package-name="capell-app/admin"')
        ->toContain('class="admin-panel-changes hidden"')
        ->and($template)
        ->toContain("'hidden' => ! \$adminPackageIsSelected");
});

it('hides downloadable packages composer cannot resolve', function (): void {
    bindSetupPluginPackagesFetcher(Collection::make([
        ['name' => 'vendor/available', 'description' => 'Can install'],
        ['name' => 'vendor/missing', 'description' => 'Cannot install'],
    ]));

    Cache::put('capell.installer.package_installable.' . hash('sha256', 'vendor/available'), true);
    Cache::put('capell.installer.package_installable.' . hash('sha256', 'vendor/missing'), false);

    get(route('capell-installer.show'))
        ->assertOk()
        ->assertSee('Available')
        ->assertDontSee('Missing');
});

it('selects default core and default extension packages in the web installer', function (): void {
    config(['capell-installer.allow_reinstall' => true]);

    $spoofedCorePackagePath = storage_path('framework/testing/installer-spoofed-core-package-' . uniqid());
    mkdir($spoofedCorePackagePath, 0755, true);
    file_put_contents($spoofedCorePackagePath . '/capell.json', json_encode(
        capellManifestV3Array('vendor/spoofed-core-package', ['console']),
        JSON_THROW_ON_ERROR,
    ));

    CapellCore::registerPackage(
        name: 'vendor/spoofed-core-package',
        path: $spoofedCorePackagePath,
        description: 'Spoofed core package',
    );

    CapellCore::registerPackage(
        name: 'vendor/composer-installed-plugin',
        description: 'Composer installed plugin',
    );
    CapellCore::clearExtensionCache();

    bindSetupPluginPackagesFetcher(Collection::make([
        ['name' => 'vendor/available', 'description' => 'Can install'],
        [
            'name' => 'capell-app/marketplace',
            'type' => PackageTypeEnum::Package->value,
            'description' => 'Marketplace',
            'defaultSelected' => true,
        ],
    ]));

    Cache::put('capell.installer.package_installable.' . hash('sha256', 'vendor/available'), true);
    Cache::put('capell.installer.package_installable.' . hash('sha256', 'capell-app/marketplace'), true);

    try {
        $html = get(route('capell-installer.show'))
            ->assertOk()
            ->content();

        expect($html)
            ->toMatch('/value="capell-app\/admin"[^>]*checked/s')
            ->toMatch('/value="capell-app\/frontend"[^>]*checked/s')
            ->toContain('name="packages[]"')
            ->toContain('value="capell-app/installer"')
            ->not->toContain('data-package-row="capell-app/installer"')
            ->toContain('value="capell-app/marketplace"')
            ->toContain('data-package-core="true"')
            ->toContain('Composer installed plugin')
            ->toContain('Spoofed core package')
            ->toContain('Marketplace')
            ->not->toMatch('/value="vendor\/available"[^>]*checked/s');

        expect($html)
            ->not->toMatch('/value="vendor\/spoofed-core-package"[^>]*checked/s')
            ->not->toMatch('/value="vendor\/composer-installed-plugin"[^>]*checked/s')
            ->toMatch('/<input[^>]*(?:value="capell-app\/marketplace"[^>]*checked|checked[^>]*value="capell-app\/marketplace")/s');

        $coreHeadingPosition = strpos($html, 'What core Capell packages should be installed?');
        $installedHeadingPosition = strpos($html, 'Installed extensions');
        $downloadableHeadingPosition = strpos($html, 'Available extensions');
        $spoofedCorePackagePosition = strpos($html, 'Spoofed core package');
        $marketplacePosition = strpos($html, 'value="capell-app/marketplace"');
        $installedPackagePosition = strpos($html, 'Composer installed plugin');
        $downloadablePackagePosition = strpos($html, 'value="vendor/available"');

        expect($coreHeadingPosition)->not->toBeFalse()
            ->and($installedHeadingPosition)->not->toBeFalse()
            ->and($downloadableHeadingPosition)->not->toBeFalse()
            ->and($spoofedCorePackagePosition)->toBeGreaterThan($installedHeadingPosition)
            ->and($spoofedCorePackagePosition)->toBeLessThan($downloadableHeadingPosition)
            ->and($installedPackagePosition)->toBeGreaterThan($installedHeadingPosition)
            ->and($installedPackagePosition)->toBeLessThan($downloadableHeadingPosition)
            ->and($marketplacePosition)->toBeGreaterThan($coreHeadingPosition)
            ->and($marketplacePosition)->toBeLessThan($installedHeadingPosition)
            ->and($downloadablePackagePosition)->toBeGreaterThan($downloadableHeadingPosition);
    } finally {
        File::deleteDirectory($spoofedCorePackagePath);
    }
});

it('selects configured default packages without treating them as core packages', function (): void {
    config(['capell-installer.default_packages' => [
        'vendor/default-installed-extension',
        'capell-app/filamentors',
    ]]);

    CapellCore::registerPackage(
        name: 'vendor/default-installed-extension',
        description: 'Default installed extension',
    );
    CapellCore::clearExtensionCache();

    bindSetupPluginPackagesFetcher(Collection::make([
        [
            'name' => 'capell-app/filamentors',
            'description' => 'Filamentors',
        ],
        [
            'name' => 'vendor/other-downloadable',
            'description' => 'Other downloadable',
        ],
    ]));

    Cache::put('capell.installer.package_installable.' . hash('sha256', 'capell-app/filamentors'), true);
    Cache::put('capell.installer.package_installable.' . hash('sha256', 'vendor/other-downloadable'), true);

    $html = get(route('capell-installer.show'))
        ->assertOk()
        ->content();

    expect($html)
        ->toMatch('/<input[^>]*value="vendor\/default-installed-extension"[^>]*data-package-core="false"/s')
        ->toMatch('/value="vendor\/default-installed-extension"[^>]*data-package-default="true"/s')
        ->toMatch('/<input[^>]*value="capell-app\/filamentors"[^>]*data-package-core="false"/s')
        ->toMatch('/value="capell-app\/filamentors"[^>]*data-package-default="true"/s')
        ->not->toMatch('/value="vendor\/other-downloadable"[^>]*checked/s');
});

it('passes configured default packages into default web installer submissions', function (): void {
    config(['capell-installer.default_packages' => ['vendor/default-installed-extension']]);

    CapellCore::registerPackage(
        name: 'vendor/default-installed-extension',
        description: 'Default installed extension',
    );
    CapellCore::clearExtensionCache();
    writeSetupFilamentUserModel();

    $spy = RunInstallAction::spy();

    post(route('capell-installer.store'), installPostPayload([
        'package_selection_mode' => 'core',
        'packages' => ['vendor/default-installed-extension'],
    ]))->assertRedirect();

    $spy->shouldHaveReceived('handle')
        ->withArgs(function (InstallInputData $inputData): bool {
            expect($inputData->packages)->toContain('vendor/default-installed-extension');

            return true;
        })
        ->once();
});

it('passes web installer option values through to install input data', function (): void {
    Queue::fake();
    $spy = RunInstallAction::spy();

    CapellCore::registerPackage('capell-app/frontend');
    CapellCore::getPackage('capell-app/frontend')->scopes = [PackageScopeEnum::Frontend];

    $routesPath = storage_path('framework/testing/installer-routes-' . uniqid() . '.php');
    $envPath = storage_path('framework/testing/installer-env-' . uniqid());
    File::ensureDirectoryExists(dirname($routesPath));
    File::put($routesPath, <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');
PHP);
    File::put($envPath, '');

    config([
        'capell.install.welcome_routes_web_path' => $routesPath,
        'capell.install.welcome_env_path' => $envPath,
    ]);

    try {
        post(route('capell-installer.store'), installPostPayload([
            'packages' => ['capell-app/frontend'],
            'generate_sitemap' => '1',
            'install_welcome_route' => '1',
            'rebuild_resources' => '1',
            'run_as_job' => null,
        ]))->assertRedirect();
    } finally {
        File::delete($routesPath);
        File::delete($envPath);
    }

    $spy->shouldHaveReceived('handle')->once()->withArgs(
        fn (InstallInputData $inputData, ProgressReporter $reporter): bool => $inputData->generateSitemap
            && $inputData->installWelcomeRoute
            && $inputData->rebuildResources
            && $inputData->assets === null,
    );
});

it('shows an already installed message when capell core is composer available and site data exists', function (): void {
    config(['capell-installer.allow_reinstall' => false]);
    Cache::forget('capell.install.lock');
    Site::factory()->createOne();

    $html = get(route('capell-installer.show'))
        ->assertOk()
        ->assertSee(__('capell-installer::installer.page_title'))
        ->assertSee(__('capell-installer::installer.already_installed_message'))
        ->assertDontSee('<p id="panel-subheading">', false)
        ->assertDontSee('id="install-form"', false)
        ->content();

    expect($html)
        ->toMatch('/<h1\\s+id="panel-heading"[^>]*>\\s*' . preg_quote(__('capell-installer::installer.page_title'), '/') . '\\s*<\\/h1>/')
        ->and(substr_count($html, __('capell-installer::installer.already_installed_message')))->toBe(1);
});

it('renders a one-time success page for completed browser installs', function (): void {
    $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/install.blade.php');
    $successView = file_get_contents(dirname(__DIR__, 2) . '/resources/views/success.blade.php');

    expect($view)
        ->not->toContain('id="completion-panel"')
        ->and($successView)
        ->not->toContain('beforeunload')
        ->toContain('data-remove-installer-form')
        ->toContain("route('capell-installer.destroy')");
});

it('only shows success page admin details once', function (): void {
    $installId = '5b1af98b-bfd5-46da-9aa8-765d94f17d7c';
    Cache::put(sprintf('capell.install.%s.success', $installId), [
        'primaryAdmin' => 'Ben Johnson <ben@example.com>',
        'roleUsersCreated' => true,
    ]);
    Cache::put(sprintf('capell.install.%s.status', $installId), 'complete');

    withSession(installerAccessSessionData($installId))
        ->get(route('capell-installer.success', ['installId' => $installId]))
        ->assertOk()
        ->assertHeader('Pragma', 'no-cache')
        ->assertSee('Ben Johnson &lt;ben@example.com&gt;', false)
        ->assertSee(__('capell-installer::installer.launchpad_check_roles'));

    withSession(installerAccessSessionData($installId))
        ->get(route('capell-installer.success', ['installId' => $installId]))
        ->assertNotFound()
        ->assertDontSee('Ben Johnson')
        ->assertDontSee(__('capell-installer::installer.launchpad_check_roles'));
});

it('does not render the success page before install completion', function (): void {
    $installId = '5b1af98b-bfd5-46da-9aa8-765d94f17d8c';
    Cache::put(sprintf('capell.install.%s.status', $installId), 'running');
    Cache::put(sprintf('capell.install.%s.success', $installId), [
        'primaryAdmin' => 'Ben Johnson <ben@example.com>',
        'roleUsersCreated' => true,
    ]);

    withSession(installerAccessSessionData($installId))
        ->get(route('capell-installer.success', ['installId' => $installId]))
        ->assertNotFound();

    expect(Cache::get(sprintf('capell.install.%s.success', $installId)))->not->toBeNull();
});

it('returns the success redirect url when the browser install completes', function (): void {
    $installId = '7b1af98b-bfd5-46da-9aa8-765d94f17d7c';

    Cache::put(sprintf('capell.install.%s.status', $installId), 'complete');

    withSession(installerAccessSessionData($installId))
        ->get(route('capell-installer.progress.data', ['installId' => $installId]))
        ->assertOk()
        ->assertJson([
            'status' => 'complete',
            'redirectUrl' => route('capell-installer.success', ['installId' => $installId]),
        ]);
});

it('rejects installer package removal without post-install session access', function (): void {
    post(route('capell-installer.destroy'))
        ->assertNotFound();
});

it('removes the installer composer package and redirects to admin when admin is installed', function (): void {
    bindSetupRemoveProcessFactory();

    CapellCore::forcePackageInstalled('capell-app/admin');

    withSession(['capell.installer.can_remove_setup_package' => true])
        ->post(route('capell-installer.destroy'))
        ->assertRedirect(url('/admin'));
});

it('removes the installer composer package and redirects to the frontend when admin is not installed', function (): void {
    bindSetupRemoveProcessFactory();
    CapellCore::clearPackages();

    withSession(['capell.installer.can_remove_setup_package' => true])
        ->post(route('capell-installer.destroy'))
        ->assertRedirect(url('/'));
});

it('shows the installer after installation when reinstall access is enabled', function (): void {
    config(['capell-installer.allow_reinstall' => true]);
    Site::factory()->createOne();

    get(route('capell-installer.show'))
        ->assertOk()
        ->assertSee(__('capell-installer::installer.heading'))
        ->assertSee(__('capell-installer::installer.option_fresh_install'))
        ->assertSee(__('capell-installer::installer.submit'));
});

it('passes freshInstall through when reinstalling an installed site', function (): void {
    config(['capell-installer.allow_reinstall' => true]);
    Site::factory()->createOne();

    Queue::fake();
    $spy = RunInstallAction::spy();

    post(route('capell-installer.store'), installPostPayload([
        'fresh_install' => '1',
    ]));

    $spy->shouldHaveReceived('handle')->once()->withArgs(
        fn (InstallInputData $input, ProgressReporter $reporter): bool => $input->freshInstall,
    );
});

it('still allows install routes while an install lock is active', function (): void {
    Site::factory()->createOne();
    Cache::put('capell.install.lock', ['installId' => 'in-flight']);
    Cache::put('capell.install.in-flight.status', 'running');

    get(route('capell-installer.show'))->assertOk();
});

it('shows the installer when site data does not exist', function (): void {
    get(route('capell-installer.show'))
        ->assertOk()
        ->assertSee('id="install-form"', false)
        ->assertDontSee(__('capell-installer::installer.already_installed_message'));
});

it('keeps the progress route reachable while users exist', function (): void {
    test()->actingAsUser();

    $installId = '22222222-2222-4222-a222-222222222222';
    Cache::put(sprintf('capell.install.%s.status', $installId), 'running');

    withSession(installerAccessSessionData($installId))
        ->get(route('capell-installer.progress.data', ['installId' => $installId]))
        ->assertOk()
        ->assertJson(['status' => 'running']);
});

it('offers existing users as admin account choices', function (): void {
    $user = User::factory()->createOne([
        'name' => 'Existing Admin',
        'email' => 'existing-admin@example.com',
    ]);

    get(route('capell-installer.show'))
        ->assertOk()
        ->assertSee('Use existing user')
        ->assertSee('Existing Admin')
        ->assertSee('existing-admin@example.com')
        ->assertSee('value="' . $user->id . '"', false);
});

it('selects an existing admin user by default when one exists', function (): void {
    $user = User::factory()->createOne([
        'name' => 'Existing Admin',
        'email' => 'existing-admin@example.com',
    ]);

    $html = get(route('capell-installer.show'))
        ->assertOk()
        ->content();

    expect($html)
        ->toMatch('/name="admin_user_mode"[^>]*value="existing"[^>]*checked/s')
        ->toMatch('/value="' . preg_quote((string) $user->id, '/') . '"[^>]*selected/s')
        ->not->toMatch('/name="new_user_name"[^>]*required/s');
});

it('prefills new admin user details from installer config defaults', function (): void {
    config()->set('capell-installer.admin_user', [
        'name' => 'Comfy Admin',
        'email' => 'comfy-admin@example.com',
        'password' => 'comfy-password',
    ]);

    $html = get(route('capell-installer.show'))
        ->assertOk()
        ->content();

    expect($html)
        ->toContain('value="Comfy Admin"')
        ->toContain('value="comfy-admin@example.com"')
        ->toContain('value="comfy-password"');
});

// ─── Queue path (run_as_job opt-in) ──────────────────────────────────────────

it('dispatches RunCapellInstallJob when run_as_job is on', function (): void {
    Queue::fake();
    $spy = RunInstallAction::spy();

    post(route('capell-installer.store'), installPostPayload(['run_as_job' => '1']))
        ->assertRedirect();

    Queue::assertPushed(RunCapellInstallJob::class);
    $spy->shouldNotHaveReceived('handle');
});

it('stores the install lock in cache for the queue path', function (): void {
    Queue::fake();

    post(route('capell-installer.store'), installPostPayload(['run_as_job' => '1']));

    $lock = Cache::get('capell.install.lock');
    expect($lock)->toBeArray()
        ->and($lock['queued'] ?? null)->toBeTrue()
        ->and($lock['installId'] ?? null)->toBeString();
});

it('cancels an owned active install before queueing another install', function (): void {
    Queue::fake();

    $previousInstallId = 'aaaaaa00-aaaa-4aaa-aaaa-aaaaaaaaaaaa';
    $newInstallId = 'bbbbbb00-bbbb-4bbb-bbbb-bbbbbbbbbbbb';

    Cache::put('capell.install.lock', ['installId' => $previousInstallId]);
    foreach (installerSessionSuffixes() as $suffix) {
        Cache::put(sprintf('capell.install.%s.%s', $previousInstallId, $suffix), 'value');
    }

    Cache::put(sprintf('capell.install.%s.status', $previousInstallId), 'running');

    withSession(installerAccessSessionData($previousInstallId))->post(route('capell-installer.store'), installPostPayload([
        'install_id' => $newInstallId,
        'run_as_job' => '1',
    ]));

    expect(Cache::get('capell.install.lock'))->toBe([
        'installId' => $newInstallId,
        'queued' => true,
    ]);
    expect(Cache::get(sprintf('capell.install.%s.status', $previousInstallId)))->toBe('cancelled');

    foreach (array_diff(installerSessionSuffixes(), ['status']) as $suffix) {
        expect(Cache::get(sprintf('capell.install.%s.%s', $previousInstallId, $suffix)))->toBeNull();
    }
});

it('does not cancel another session active install before queueing another install', function (): void {
    Queue::fake();

    $previousInstallId = 'aaaaaa00-aaaa-4aaa-aaaa-aaaaaaaaaaab';
    $newInstallId = 'bbbbbb00-bbbb-4bbb-bbbb-bbbbbbbbbbba';

    Cache::put('capell.install.lock', ['installId' => $previousInstallId]);
    Cache::put(sprintf('capell.install.%s.status', $previousInstallId), 'running');
    Cache::put(sprintf('capell.install.%s.input', $previousInstallId), ['site_url' => 'https://old.example.com']);

    post(route('capell-installer.store'), installPostPayload([
        'install_id' => $newInstallId,
        'run_as_job' => '1',
    ]))->assertRedirect()->assertSessionHasErrors('install');

    expect(Cache::get('capell.install.lock'))->toBe(['installId' => $previousInstallId])
        ->and(Cache::get(sprintf('capell.install.%s.status', $previousInstallId)))->toBe('running')
        ->and(Cache::get(sprintf('capell.install.%s.input', $previousInstallId)))->toBe(['site_url' => 'https://old.example.com']);

    Queue::assertNothingPushed();
});

it('returns a json cache-store remediation before queueing when progress cache is unavailable', function (): void {
    Queue::fake();
    config()->set('cache.default', 'database');
    config()->set('cache.stores.database.table', 'missing_installer_cache_table');

    post(
        route('capell-installer.store'),
        installPostPayload(['run_as_job' => '1']),
        ['Accept' => 'application/json'],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cache_store'])
        ->assertJsonPath('message', 'CACHE_STORE=database requires the cache table before the web installer can track progress.');

    Queue::assertNothingPushed();
});

it('redirects back with cache-store remediation when a non-ajax install cannot track progress', function (): void {
    Queue::fake();
    config()->set('cache.default', 'database');
    config()->set('cache.stores.database.table', 'missing_installer_cache_table');

    post(route('capell-installer.store'), installPostPayload())
        ->assertRedirect()
        ->assertSessionHasErrors(['cache_store']);

    Queue::assertNothingPushed();
});

// ─── Direct path (default for non-AJAX) ──────────────────────────────────────

it('calls RunInstallAction directly when run_as_job is off (default)', function (): void {
    Queue::fake();
    $spy = RunInstallAction::spy();

    post(route('capell-installer.store'), installPostPayload())
        ->assertRedirect();

    $spy->shouldHaveReceived('handle')->once()->withArgs(
        fn (InstallInputData $input, ProgressReporter $reporter): bool => $input->siteUrl === 'https://example.com'
            && $input->languages === ['en']
            && $input->seedDefaultData,
    );

    Queue::assertNothingPushed();
});

it('passes a selected web installer theme when the matching package is selected', function (): void {
    Queue::fake();
    $spy = RunInstallAction::spy();

    CapellCore::registerPackage('capell-app/theme-corporate', type: PackageTypeEnum::Theme);
    CapellCore::getPackage('capell-app/theme-corporate')->themeKey = 'corporate';

    post(route('capell-installer.store'), installPostPayload([
        'packages' => ['capell-app/theme-corporate'],
        'theme' => 'corporate',
    ]));

    $spy->shouldHaveReceived('handle')->once()->withArgs(
        fn (InstallInputData $input, ProgressReporter $reporter): bool => $input->selectedThemeKey === 'corporate',
    );
});

it('adds the selected web installer theme package when it is not otherwise selected', function (): void {
    CapellCore::registerPackage('capell-app/theme-corporate', type: PackageTypeEnum::Theme);
    CapellCore::getPackage('capell-app/theme-corporate')->themeKey = 'corporate';
    $spy = RunInstallAction::spy();

    post(
        route('capell-installer.store'),
        installPostPayload(['theme' => 'corporate']),
    );

    $spy->shouldHaveReceived('handle')->once()->withArgs(
        fn (InstallInputData $input, ProgressReporter $reporter): bool => in_array('capell-app/theme-corporate', $input->packages, true)
            && $input->selectedThemeKey === 'corporate',
    );
});

it('accepts web installer theme keys from downloadable theme packages', function (): void {
    Queue::fake();
    $spy = RunInstallAction::spy();

    bindSetupPluginPackagesFetcher(Collection::make([
        [
            'name' => 'capell-app/theme-agency',
            'type' => PackageTypeEnum::Theme->value,
            'themeKey' => 'agency',
        ],
    ]));
    Cache::put('capell.installer.package_installable.' . hash('sha256', 'capell-app/theme-agency'), true);

    post(route('capell-installer.store'), installPostPayload([
        'theme' => 'agency',
    ]));

    $spy->shouldHaveReceived('handle')->once()->withArgs(
        fn (InstallInputData $input, ProgressReporter $reporter): bool => $input->selectedThemeKey === 'agency'
            && $input->extraPackages === ['capell-app/theme-agency'],
    );
});

it('passes seedDefaultData false when toggle is off', function (): void {
    Queue::fake();
    $spy = RunInstallAction::spy();

    post(route('capell-installer.store'), installPostPayload([
        'seed_default_data' => null,
    ]));

    $spy->shouldHaveReceived('handle')->once()->withArgs(
        fn (InstallInputData $input, ProgressReporter $reporter): bool => $input->seedDefaultData === false,
    );
});

it('passes example role users through web installer input', function (): void {
    Queue::fake();
    $spy = RunInstallAction::spy();

    post(route('capell-installer.store'), installPostPayload([
        'create_role_users' => '1',
    ]));

    $spy->shouldHaveReceived('handle')->once()->withArgs(
        fn (InstallInputData $input, ProgressReporter $reporter): bool => count($input->additionalUsers) === 2
            && $input->additionalUsers[0]->email === 'super-admin@example.test'
            && $input->additionalUsers[0]->roleName === 'super_admin'
            && $input->additionalUsers[1]->email === 'editor@example.test'
            && $input->additionalUsers[1]->roleName === 'editor',
    );
});

it('shows the starter role users option while any starter role is missing', function (): void {
    Role::query()->where('name', 'editor')->delete();

    get(route('capell-installer.show'))
        ->assertOk()
        ->assertSee(__('capell-installer::installer.option_create_role_users'));
});

it('hides the starter role users option when all starter roles exist', function (): void {
    Role::findOrCreate('editor', 'web');

    get(route('capell-installer.show'))
        ->assertOk()
        ->assertDontSee(__('capell-installer::installer.option_create_role_users'));
});

it('uses the new admin password for example role users when their password is blank', function (): void {
    Queue::fake();
    $spy = RunInstallAction::spy();

    post(route('capell-installer.store'), installPostPayload([
        'create_role_users' => '1',
        'new_user_password' => 'admin-password',
        'role_user_password' => null,
    ]));

    $spy->shouldHaveReceived('handle')->once()->withArgs(
        fn (InstallInputData $input, ProgressReporter $reporter): bool => count($input->additionalUsers) === 2
            && $input->additionalUsers[0]->password === 'admin-password'
            && $input->additionalUsers[1]->password === 'admin-password',
    );
});

it('forces seedDefaultData true when demo_content is on regardless of toggle', function (): void {
    Queue::fake();
    $spy = RunInstallAction::spy();

    post(route('capell-installer.store'), installPostPayload([
        'demo_content' => '1',
        'seed_default_data' => null,
    ]));

    $spy->shouldHaveReceived('handle')->once()->withArgs(
        fn (InstallInputData $input, ProgressReporter $reporter): bool => $input->seedDefaultData,
    );
});

it('passes installFilamentPanel through when toggle is on', function (): void {
    Queue::fake();
    $spy = RunInstallAction::spy();

    post(route('capell-installer.store'), installPostPayload([
        'install_filament_panel' => '1',
    ]));

    $spy->shouldHaveReceived('handle')->once()->withArgs(
        fn (InstallInputData $input, ProgressReporter $reporter): bool => $input->installFilamentPanel,
    );
});

it('passes rebuildResources through when toggle is on', function (): void {
    Queue::fake();
    $spy = RunInstallAction::spy();

    post(route('capell-installer.store'), installPostPayload([
        'rebuild_resources' => '1',
    ]));

    $spy->shouldHaveReceived('handle')->once()->withArgs(
        fn (InstallInputData $input, ProgressReporter $reporter): bool => $input->rebuildResources,
    );
});

it('passes unchecked admin integration options as false', function (): void {
    Queue::fake();
    $spy = RunInstallAction::spy();
    CapellCore::registerPackage(name: 'capell-app/admin');

    try {
        writeSetupFilamentUserModel();

        post(route('capell-installer.store'), installPostPayload([
            'packages' => ['capell-app/admin'],
            'integrate_admin_panel' => null,
            'admin_add_colors' => null,
            'admin_add_widgets' => null,
            'admin_add_navigation' => null,
        ]));
    } finally {
        if (is_dir(base_path('app'))) {
            exec('rm -rf ' . escapeshellarg(base_path('app')));
        }
    }

    $spy->shouldHaveReceived('handle')->once()->withArgs(
        fn (InstallInputData $input, ProgressReporter $reporter): bool => $input->integrateAdminPanel === false
            && $input->adminAddColors === false
            && $input->adminAddWidgets === false
            && $input->adminAddNavigation === false,
    );
});

it('passes checked admin integration options as true', function (): void {
    Queue::fake();
    $spy = RunInstallAction::spy();
    CapellCore::registerPackage(name: 'capell-app/admin');

    try {
        writeSetupFilamentUserModel();

        post(route('capell-installer.store'), installPostPayload([
            'packages' => ['capell-app/admin'],
            'integrate_admin_panel' => '1',
            'admin_add_colors' => '1',
            'admin_add_widgets' => '1',
            'admin_add_navigation' => '1',
        ]));
    } finally {
        if (is_dir(base_path('app'))) {
            exec('rm -rf ' . escapeshellarg(base_path('app')));
        }
    }

    $spy->shouldHaveReceived('handle')->once()->withArgs(
        fn (InstallInputData $input, ProgressReporter $reporter): bool => $input->integrateAdminPanel
            && $input->adminAddColors
            && $input->adminAddWidgets
            && $input->adminAddNavigation,
    );
});

it('auto applies all admin panel changes from the radio choice', function (): void {
    Queue::fake();
    $spy = RunInstallAction::spy();
    CapellCore::registerPackage(name: 'capell-app/admin');

    try {
        writeSetupFilamentUserModel();

        post(route('capell-installer.store'), installPostPayload([
            'packages' => ['capell-app/admin'],
            'admin_panel_changes_mode' => 'auto',
            'admin_panel' => 'CustomPanelProvider',
            'admin_discover_schemas' => 'app/Filament/Custom=App\\Filament\\Custom',
            'integrate_admin_panel' => null,
            'admin_add_colors' => null,
            'admin_add_widgets' => null,
            'admin_add_navigation' => null,
        ]));
    } finally {
        if (is_dir(base_path('app'))) {
            exec('rm -rf ' . escapeshellarg(base_path('app')));
        }
    }

    $spy->shouldHaveReceived('handle')->once()->withArgs(
        fn (InstallInputData $input, ProgressReporter $reporter): bool => $input->integrateAdminPanel
            && $input->adminPanel === null
            && $input->adminDiscoverSchemas === []
            && $input->adminAddColors
            && $input->adminAddWidgets
            && $input->adminAddNavigation,
    );
});

it('leaves admin panel changes for manual installer from the radio choice', function (): void {
    Queue::fake();
    $spy = RunInstallAction::spy();
    CapellCore::registerPackage(name: 'capell-app/admin');

    try {
        writeSetupFilamentUserModel();

        post(route('capell-installer.store'), installPostPayload([
            'packages' => ['capell-app/admin'],
            'admin_panel_changes_mode' => 'manual',
            'integrate_admin_panel' => '1',
            'admin_add_colors' => '1',
            'admin_add_widgets' => '1',
            'admin_add_navigation' => '1',
        ]));
    } finally {
        if (is_dir(base_path('app'))) {
            exec('rm -rf ' . escapeshellarg(base_path('app')));
        }
    }

    $spy->shouldHaveReceived('handle')->once()->withArgs(
        fn (InstallInputData $input, ProgressReporter $reporter): bool => $input->integrateAdminPanel === false
            && $input->adminAddColors === false
            && $input->adminAddWidgets === false
            && $input->adminAddNavigation === false,
    );
});

it('passes an existing admin user id through instead of creating a new user', function (): void {
    Queue::fake();
    $spy = RunInstallAction::spy();
    $user = User::factory()->createOne();

    post(route('capell-installer.store'), installPostPayload([
        'admin_user_mode' => 'existing',
        'existing_user_id' => (string) $user->id,
        'new_user_name' => null,
        'new_user_email' => null,
        'new_user_password' => null,
    ]))
        ->assertRedirect();

    $spy->shouldHaveReceived('handle')->once()->withArgs(
        fn (InstallInputData $input, ProgressReporter $reporter): bool => $input->userId === $user->id
            && ! $input->newUser instanceof NewUserData,
    );
});

it('marks status as failed when RunInstallAction throws', function (): void {
    Queue::fake();
    $installId = 'bbbbbb00-bbbb-4bbb-bbbb-bbbbbbbbbbbb';
    RunInstallAction::mock()
        ->shouldReceive('handle')
        ->once()
        ->andThrow(new RuntimeException('Boom'));

    $response = post(route('capell-installer.store'), installPostPayload(['install_id' => $installId]));

    $response->assertRedirect();

    expect(Cache::get(sprintf('capell.install.%s.status', $installId)))->toBe('failed')
        ->and(Cache::get('capell.install.lock'))->toBeNull();
});

it('cancels an owned active install before running a synchronous install', function (): void {
    Queue::fake();
    RunInstallAction::spy();

    $previousInstallId = 'eeeeee00-eeee-4eee-eeee-eeeeeeeeeeee';
    $newInstallId = 'ffffff00-ffff-4fff-ffff-ffffffffffff';

    Cache::put('capell.install.lock', ['installId' => $previousInstallId]);
    foreach (installerSessionSuffixes() as $suffix) {
        Cache::put(sprintf('capell.install.%s.%s', $previousInstallId, $suffix), 'value');
    }

    Cache::put(sprintf('capell.install.%s.status', $previousInstallId), 'running');

    withSession(installerAccessSessionData($previousInstallId))->post(route('capell-installer.store'), installPostPayload(['install_id' => $newInstallId]))
        ->assertRedirect(route('capell-installer.success', ['installId' => $newInstallId]));

    expect(Cache::get(sprintf('capell.install.%s.status', $previousInstallId)))->toBe('cancelled')
        ->and(Cache::get(sprintf('capell.install.%s.input', $previousInstallId)))->toBeNull()
        ->and(Cache::get(sprintf('capell.install.%s.plan', $previousInstallId)))->toBeNull()
        ->and(Cache::get(sprintf('capell.install.%s.output', $previousInstallId)))->toBeNull()
        ->and(Cache::get(sprintf('capell.install.%s.user_id', $previousInstallId)))->toBeNull()
        ->and(Cache::get(sprintf('capell.install.%s.preflight', $previousInstallId)))->toBeNull()
        ->and(Cache::get(sprintf('capell.install.%s.success', $previousInstallId)))->toBeNull();
});

it('does not cancel another session active install before running a synchronous install', function (): void {
    Queue::fake();
    $spy = RunInstallAction::spy();

    $previousInstallId = 'eeeeee00-eeee-4eee-eeee-eeeeeeeeeeef';
    $newInstallId = 'ffffff00-ffff-4fff-ffff-fffffffffffa';

    Cache::put('capell.install.lock', ['installId' => $previousInstallId]);
    Cache::put(sprintf('capell.install.%s.status', $previousInstallId), 'running');
    Cache::put(sprintf('capell.install.%s.input', $previousInstallId), ['site_url' => 'https://old.example.com']);

    post(route('capell-installer.store'), installPostPayload(['install_id' => $newInstallId]))
        ->assertRedirect()
        ->assertSessionHasErrors('install');

    expect(Cache::get('capell.install.lock'))->toBe(['installId' => $previousInstallId])
        ->and(Cache::get(sprintf('capell.install.%s.status', $previousInstallId)))->toBe('running')
        ->and(Cache::get(sprintf('capell.install.%s.input', $previousInstallId)))->toBe(['site_url' => 'https://old.example.com']);

    $spy->shouldNotHaveReceived('handle');
});

// ─── AJAX step-based path (default) ──────────────────────────────────────────

it('returns the install plan when posted as ajax', function (): void {
    Queue::fake();
    $spy = RunInstallAction::spy();

    $response = post(
        route('capell-installer.store'),
        installPostPayload(),
        ['Accept' => 'application/json'],
    );

    $response->assertOk();
    $response->assertJsonStructure([
        'installId',
        'status',
        'plan',
        'nextStep',
        'runStepUrl',
        'progressUrl',
        'progressDataUrl',
        'reportUrl',
        'logPath',
    ]);
    $response->assertJsonPath('plan.0.key', InstallPlan::STEP_PREFLIGHT_CHECKS);

    Queue::assertNothingPushed();
    $spy->shouldNotHaveReceived('handle');
});

it('cancels an owned active install before preparing an ajax install plan', function (): void {
    Queue::fake();
    RunInstallAction::spy();

    $previousInstallId = 'cccccc00-cccc-4ccc-cccc-cccccccccccc';
    $newInstallId = 'dddddd00-dddd-4ddd-dddd-dddddddddddd';

    Cache::put('capell.install.lock', ['installId' => $previousInstallId]);
    foreach (installerSessionSuffixes() as $suffix) {
        Cache::put(sprintf('capell.install.%s.%s', $previousInstallId, $suffix), 'value');
    }

    Cache::put(sprintf('capell.install.%s.status', $previousInstallId), 'running');

    withSession(installerAccessSessionData($previousInstallId))->post(
        route('capell-installer.store'),
        installPostPayload(['install_id' => $newInstallId]),
        ['Accept' => 'application/json'],
    )->assertOk()->assertJson(['installId' => $newInstallId]);

    expect(Cache::get('capell.install.lock'))->toBe(['installId' => $newInstallId])
        ->and(Cache::get(sprintf('capell.install.%s.status', $previousInstallId)))->toBe('cancelled')
        ->and(Cache::get(sprintf('capell.install.%s.input', $previousInstallId)))->toBeNull()
        ->and(Cache::get(sprintf('capell.install.%s.plan', $previousInstallId)))->toBeNull()
        ->and(Cache::get(sprintf('capell.install.%s.output', $previousInstallId)))->toBeNull()
        ->and(Cache::get(sprintf('capell.install.%s.user_id', $previousInstallId)))->toBeNull()
        ->and(Cache::get(sprintf('capell.install.%s.preflight', $previousInstallId)))->toBeNull()
        ->and(Cache::get(sprintf('capell.install.%s.success', $previousInstallId)))->toBeNull();
});

it('does not cancel another session active install before preparing an ajax install plan', function (): void {
    Queue::fake();
    RunInstallAction::spy();

    $previousInstallId = 'cccccc00-cccc-4ccc-cccc-cccccccccccd';
    $newInstallId = 'dddddd00-dddd-4ddd-dddd-ddddddddddde';

    Cache::put('capell.install.lock', ['installId' => $previousInstallId]);
    Cache::put(sprintf('capell.install.%s.status', $previousInstallId), 'running');
    Cache::put(sprintf('capell.install.%s.input', $previousInstallId), ['site_url' => 'https://old.example.com']);

    post(
        route('capell-installer.store'),
        installPostPayload(['install_id' => $newInstallId]),
        ['Accept' => 'application/json'],
    )->assertConflict()->assertJsonPath('message', 'Another install is already running in a different browser session.');

    expect(Cache::get('capell.install.lock'))->toBe(['installId' => $previousInstallId])
        ->and(Cache::get(sprintf('capell.install.%s.status', $previousInstallId)))->toBe('running')
        ->and(Cache::get(sprintf('capell.install.%s.input', $previousInstallId)))->toBe(['site_url' => 'https://old.example.com']);
});

it('patches the app user model before returning the ajax install plan for admin', function (): void {
    acquireCapellInstallFilesystemLock();

    Queue::fake();
    RunInstallAction::spy();

    $userModelPath = base_path('app/Models/User.php');

    if (! is_dir(dirname($userModelPath))) {
        mkdir(dirname($userModelPath), 0755, true);
    }

    file_put_contents($userModelPath, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
PHP);

    try {
        post(
            route('capell-installer.store'),
            installPostPayload([
                'packages' => ['capell-app/admin'],
                'install_id' => '99990000-9999-4999-a999-999999990000',
            ]),
            ['Accept' => 'application/json'],
        )->assertOk();

        expect(file_get_contents($userModelPath))
            ->toContain('use Spatie\Permission\Traits\HasRoles;')
            ->toContain('use Notifiable, HasImpersonation, HasPanelShield, HasRoles, HasSitePermissions, LogsActivity;')
            ->not->toContain('Capell\Admin\Traits');
    } finally {
        if (is_dir(base_path('app'))) {
            exec('rm -rf ' . escapeshellarg(base_path('app')));
        }
    }
});

it('returns a diagnostic install report', function (): void {
    Queue::fake();
    RunInstallAction::spy();

    $response = post(
        route('capell-installer.store'),
        installPostPayload(['install_id' => '77777777-7777-4777-a777-777777777777']),
        ['Accept' => 'application/json'],
    );

    $response->assertOk();

    expect($response->json('reportUrl'))
        ->toBe(route('capell-installer.progress.download', ['installId' => '77777777-7777-4777-a777-777777777777']));

    $reportResponse = get($response->json('reportUrl'));

    $reportResponse
        ->assertOk()
        ->assertHeader('content-type', 'application/json')
        ->assertHeader('content-disposition', 'attachment; filename="capell-install-77777777-7777-4777-a777-777777777777.json"')
        ->assertJsonPath('installId', '77777777-7777-4777-a777-777777777777')
        ->assertJsonStructure([
            'installId',
            'status',
            'environment',
            'preflight',
            'plan',
            'diagnostics' => ['steps'],
            'selected',
            'lines',
            'remediations',
        ]);
});

it('returns a json error for an invalid install report id', function (): void {
    get(route('capell-installer.progress.download', ['installId' => 'not-a-uuid']))
        ->assertNotFound()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonPath('error', 'Install report not found.');
});

it('does not generate diagnostic reports for unknown install ids', function (): void {
    get(route('capell-installer.progress.download', ['installId' => '99999999-9999-4999-a999-999999999999']))
        ->assertNotFound()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonPath('error', 'Install report not found.');
});

it('returns 404 for unknown progress install ids', function (): void {
    get(route('capell-installer.progress', ['installId' => '99999999-9999-4999-a999-888888888888']))
        ->assertNotFound();

    get(route('capell-installer.progress.data', ['installId' => '99999999-9999-4999-a999-777777777777']))
        ->assertNotFound();
});

it('returns a json error when diagnostic report generation fails', function (): void {
    app()->bind(InstallerPreflight::class, fn (): never => throw new RuntimeException('Preflight unavailable.'));

    Cache::put('capell.install.88888888-8888-4888-a888-888888888888.status', 'running');

    withSession(installerAccessSessionData('88888888-8888-4888-a888-888888888888'))
        ->get(route('capell-installer.progress.download', ['installId' => '88888888-8888-4888-a888-888888888888']))
        ->assertInternalServerError()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonPath('error', 'Preflight unavailable.');
});

it('redirects to progress page when ajax with run_as_job', function (): void {
    Queue::fake();

    $response = post(
        route('capell-installer.store'),
        installPostPayload(['run_as_job' => '1']),
        ['Accept' => 'application/json'],
    );

    $response->assertOk();
    $response->assertJsonStructure(['installId', 'status', 'progressUrl', 'progressDataUrl', 'redirectUrl']);

    Queue::assertPushed(RunCapellInstallJob::class);
});

it('returns a json user model remediation when queued admin installation cannot patch a customized model', function (): void {
    acquireCapellInstallFilesystemLock();
    Queue::fake();
    CapellCore::registerPackage(name: 'capell-app/admin');
    $userModelPath = base_path('app/Models/User.php');

    if (! is_dir(dirname($userModelPath))) {
        mkdir(dirname($userModelPath), 0755, true);
    }

    file_put_contents($userModelPath, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

class User extends CustomBaseUser
{
}
PHP);

    try {
        post(
            route('capell-installer.store'),
            installPostPayload([
                'packages' => ['capell-app/admin'],
                'run_as_job' => '1',
            ]),
            ['Accept' => 'application/json'],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_model'])
            ->assertJsonPath('message', 'The installer could not automatically update app/Models/User.php for Capell admin roles because the user model patch status is "customised". Apply the user model install guide patch, then rerun the installer.');
    } finally {
        if (is_dir(base_path('app'))) {
            exec('rm -rf ' . escapeshellarg(base_path('app')));
        }
    }

    Queue::assertNothingPushed();
});

it('returns a web user model remediation when queued admin installation cannot patch a customized model', function (): void {
    acquireCapellInstallFilesystemLock();
    Queue::fake();
    CapellCore::registerPackage(name: 'capell-app/admin');
    $userModelPath = base_path('app/Models/User.php');

    if (! is_dir(dirname($userModelPath))) {
        mkdir(dirname($userModelPath), 0755, true);
    }

    file_put_contents($userModelPath, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

class User extends CustomBaseUser
{
}
PHP);

    try {
        post(route('capell-installer.store'), installPostPayload([
            'packages' => ['capell-app/admin'],
            'run_as_job' => '1',
        ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['user_model']);
    } finally {
        if (is_dir(base_path('app'))) {
            exec('rm -rf ' . escapeshellarg(base_path('app')));
        }
    }

    Queue::assertNothingPushed();
});

it('returns a json user model remediation before preparing step-based admin installs', function (): void {
    acquireCapellInstallFilesystemLock();
    CapellCore::registerPackage(name: 'capell-app/admin');
    $userModelPath = base_path('app/Models/User.php');

    if (! is_dir(dirname($userModelPath))) {
        mkdir(dirname($userModelPath), 0755, true);
    }

    file_put_contents($userModelPath, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

class User extends CustomBaseUser
{
}
PHP);

    try {
        post(
            route('capell-installer.store'),
            installPostPayload([
                'packages' => ['capell-app/admin'],
                'run_as_job' => null,
            ]),
            ['Accept' => 'application/json'],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_model']);
    } finally {
        if (is_dir(base_path('app'))) {
            exec('rm -rf ' . escapeshellarg(base_path('app')));
        }
    }
});

it('uses a client-provided install_id when submitted', function (): void {
    Queue::fake();
    RunInstallAction::spy();

    $clientInstallId = '11111111-1111-4111-a111-111111111111';

    $response = post(
        route('capell-installer.store'),
        installPostPayload(['install_id' => $clientInstallId]),
        ['Accept' => 'application/json'],
    );

    $response->assertOk();
    $response->assertJson(['installId' => $clientInstallId]);
});

it('returns 422 with field errors on validation failure when ajax', function (): void {
    $response = post(
        route('capell-installer.store'),
        [],
        ['Accept' => 'application/json'],
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['site_url', 'language', 'new_user_name', 'new_user_email', 'new_user_password']);
});

// ─── Step-based runStep endpoint ─────────────────────────────────────────────

it('runs a single install step via the run-step endpoint', function (): void {
    $installId = '22222222-2222-4222-a222-222222222222';
    $inputData = new InstallInputData(
        siteUrl: 'https://example.com',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        newUser: new NewUserData(name: 'Admin', email: 'admin@example.com', password: 'password123'),
    );
    $plan = InstallPlan::build($inputData);

    Cache::put(sprintf('capell.install.%s.input', $installId), $inputData->toArray());
    Cache::put(sprintf('capell.install.%s.plan', $installId), $plan);
    Cache::put(sprintf('capell.install.%s.current_step', $installId), InstallPlan::STEP_PREPARE_ENVIRONMENT);
    Cache::put(sprintf('capell.install.%s.completed_steps', $installId), [InstallPlan::STEP_PREFLIGHT_CHECKS]);

    $spy = RunInstallStepAction::spy();

    withSession(installerAccessSessionData($installId))->post(
        route('capell-installer.run-step'),
        [
            'install_id' => $installId,
            'step' => InstallPlan::STEP_PREPARE_ENVIRONMENT,
        ],
        ['Accept' => 'application/json'],
    )
        ->assertOk()
        ->assertJson([
            'installId' => $installId,
            'currentStep' => InstallPlan::STEP_PREPARE_ENVIRONMENT,
            'status' => 'running',
        ])
        ->assertJsonStructure(['nextStep', 'lines', 'logPath']);

    $spy->shouldHaveReceived('handle')->once();

    expect(resolve(InstallerSessionRepository::class)->stepDiagnostics($installId))
        ->toHaveKey(InstallPlan::STEP_PREPARE_ENVIRONMENT . '.peakMemoryBytes');
});

it('rejects run-step requests ahead of the current installer step', function (): void {
    $installId = '22222222-2222-4222-a222-222222222225';
    $inputData = new InstallInputData(
        siteUrl: 'https://example.com',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        newUser: new NewUserData(name: 'Admin', email: 'admin@example.com', password: 'password123'),
    );
    $plan = InstallPlan::build($inputData);
    $lastPlanStep = end($plan);
    assert(is_array($lastPlanStep));

    Cache::put(sprintf('capell.install.%s.input', $installId), $inputData->toArray());
    Cache::put(sprintf('capell.install.%s.plan', $installId), $plan);
    Cache::put(sprintf('capell.install.%s.current_step', $installId), InstallPlan::STEP_PREFLIGHT_CHECKS);
    Cache::put(sprintf('capell.install.%s.completed_steps', $installId), []);

    $spy = RunInstallStepAction::spy();

    withSession(installerAccessSessionData($installId))->post(
        route('capell-installer.run-step'),
        [
            'install_id' => $installId,
            'step' => $lastPlanStep['key'],
        ],
        ['Accept' => 'application/json'],
    )
        ->assertConflict()
        ->assertJson([
            'installId' => $installId,
            'currentStep' => $lastPlanStep['key'],
            'nextStep' => InstallPlan::STEP_PREFLIGHT_CHECKS,
            'expectedStep' => InstallPlan::STEP_PREFLIGHT_CHECKS,
            'status' => 'failed',
        ]);

    $spy->shouldNotHaveReceived('handle');
});

it('continues from the current step when a completed run-step request is retried', function (): void {
    $installId = '22222222-2222-4222-a222-222222222226';
    $inputData = new InstallInputData(
        siteUrl: 'https://example.com',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        newUser: new NewUserData(name: 'Admin', email: 'admin@example.com', password: 'password123'),
    );
    $plan = InstallPlan::build($inputData);

    Cache::put(sprintf('capell.install.%s.input', $installId), $inputData->toArray());
    Cache::put(sprintf('capell.install.%s.plan', $installId), $plan);
    Cache::put(sprintf('capell.install.%s.current_step', $installId), InstallPlan::STEP_PUBLISH_VENDOR_MIGRATIONS);
    Cache::put(sprintf('capell.install.%s.completed_steps', $installId), [
        InstallPlan::STEP_PREFLIGHT_CHECKS,
        InstallPlan::STEP_PREPARE_ENVIRONMENT,
    ]);

    $spy = RunInstallStepAction::spy();

    withSession(installerAccessSessionData($installId))->post(
        route('capell-installer.run-step'),
        [
            'install_id' => $installId,
            'step' => InstallPlan::STEP_PREPARE_ENVIRONMENT,
        ],
        ['Accept' => 'application/json'],
    )
        ->assertOk()
        ->assertJson([
            'installId' => $installId,
            'currentStep' => InstallPlan::STEP_PREPARE_ENVIRONMENT,
            'nextStep' => InstallPlan::STEP_PUBLISH_VENDOR_MIGRATIONS,
            'status' => 'running',
        ]);

    $spy->shouldNotHaveReceived('handle');
});

it('runs preflight as the first browser install step and returns the next plan step', function (): void {
    $installId = '22222222-2222-4222-a222-222222222223';
    $inputData = new InstallInputData(
        siteUrl: 'https://example.com',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        newUser: new NewUserData(name: 'Admin', email: 'admin@example.com', password: 'password123'),
    );
    $plan = InstallPlan::build($inputData);

    Cache::put(sprintf('capell.install.%s.input', $installId), $inputData->toArray());
    Cache::put(sprintf('capell.install.%s.plan', $installId), $plan);

    app()->instance(InstallerPreflight::class, new class
    {
        public function run(?InstallInputData $inputData = null): array
        {
            return [
                'status' => 'warning',
                'generatedAt' => now()->toIso8601String(),
                'environment' => ['php' => PHP_VERSION],
                'groups' => ['blocking' => [], 'advisory' => []],
                'checks' => [
                    [
                        'key' => 'php',
                        'label' => 'PHP',
                        'status' => 'pass',
                        'severity' => 'blocking',
                        'message' => 'Ready',
                        'remediation' => null,
                    ],
                    [
                        'key' => 'queue',
                        'label' => 'Queue',
                        'status' => 'warning',
                        'severity' => 'advisory',
                        'message' => 'Worker not detected',
                        'remediation' => 'Start a queue worker before production traffic.',
                    ],
                ],
            ];
        }
    });

    $response = withSession(installerAccessSessionData($installId))->post(
        route('capell-installer.run-step'),
        [
            'install_id' => $installId,
            'step' => InstallPlan::STEP_PREFLIGHT_CHECKS,
        ],
        ['Accept' => 'application/json'],
    );

    $response->assertOk()
        ->assertJson([
            'installId' => $installId,
            'currentStep' => InstallPlan::STEP_PREFLIGHT_CHECKS,
            'nextStep' => $plan[1]['key'],
            'status' => 'running',
        ])
        ->assertJsonPath('preflight.status', 'warning');

    expect(Cache::get(sprintf('capell.install.%s.preflight', $installId)))
        ->toHaveKey('checks')
        ->and(Cache::get(sprintf('capell.install.%s.current_step', $installId)))->toBe($plan[1]['key'])
        ->and(Cache::get(sprintf('capell.install.%s.completed_steps', $installId)))->toBe([InstallPlan::STEP_PREFLIGHT_CHECKS]);
});

it('stops browser install steps when preflight reports blocking failures', function (): void {
    $installId = '22222222-2222-4222-a222-222222222224';
    $inputData = new InstallInputData(
        siteUrl: 'https://example.com',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        newUser: new NewUserData(name: 'Admin', email: 'admin@example.com', password: 'password123'),
    );
    $plan = InstallPlan::build($inputData);

    Cache::put(InstallerSessionRepository::LOCK_KEY, ['installId' => $installId]);
    Cache::put(sprintf('capell.install.%s.input', $installId), $inputData->toArray());
    Cache::put(sprintf('capell.install.%s.plan', $installId), $plan);

    app()->instance(InstallerPreflight::class, new class
    {
        public function run(?InstallInputData $inputData = null): array
        {
            return [
                'status' => 'fail',
                'generatedAt' => now()->toIso8601String(),
                'environment' => [],
                'groups' => ['blocking' => [], 'advisory' => []],
                'checks' => [
                    [
                        'key' => 'cache',
                        'label' => 'Cache store',
                        'status' => 'fail',
                        'severity' => 'blocking',
                        'message' => 'Cache table missing',
                        'remediation' => 'Run php artisan cache:table && php artisan migrate.',
                    ],
                ],
            ];
        }
    });

    $response = withSession(installerAccessSessionData($installId))->post(
        route('capell-installer.run-step'),
        [
            'install_id' => $installId,
            'step' => InstallPlan::STEP_PREFLIGHT_CHECKS,
        ],
        ['Accept' => 'application/json'],
    );

    $response->assertOk()
        ->assertJson([
            'installId' => $installId,
            'currentStep' => InstallPlan::STEP_PREFLIGHT_CHECKS,
            'nextStep' => null,
            'status' => 'failed',
            'error' => 'Preflight checks failed.',
        ])
        ->assertJsonPath('remediation', 'Run php artisan cache:table && php artisan migrate.');

    expect(Cache::get(InstallerSessionRepository::LOCK_KEY))->toBeNull();
});

it('patches the app user model before resolving the install user when admin is selected', function (): void {
    acquireCapellInstallFilesystemLock();

    $installId = '77770000-7777-4777-a777-777777770000';
    $userModelPath = base_path('app/Models/User.php');

    if (! is_dir(dirname($userModelPath))) {
        mkdir(dirname($userModelPath), 0755, true);
    }

    file_put_contents($userModelPath, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
PHP);

    try {
        $inputData = new InstallInputData(
            siteUrl: 'https://example.com',
            packages: ['capell-app/admin'],
            languages: ['en'],
            demoContent: false,
            cachesToClear: [],
            generateSitemap: false,
            generateStaticSite: false,
            newUser: new NewUserData(name: 'Admin', email: 'admin@example.com', password: 'password123'),
            seedDefaultData: true,
        );
        $plan = InstallPlan::build($inputData);

        Cache::put(sprintf('capell.install.%s.input', $installId), $inputData->toArray());
        Cache::put(sprintf('capell.install.%s.plan', $installId), $plan);
        Cache::put(sprintf('capell.install.%s.user_id', $installId), 1);
        Cache::put(sprintf('capell.install.%s.current_step', $installId), InstallPlan::STEP_RESOLVE_USER);
        Cache::put(sprintf('capell.install.%s.completed_steps', $installId), [InstallPlan::STEP_PREFLIGHT_CHECKS]);

        $spy = RunInstallStepAction::spy();

        $response = withSession(installerAccessSessionData($installId))->post(
            route('capell-installer.run-step'),
            [
                'install_id' => $installId,
                'step' => InstallPlan::STEP_RESOLVE_USER,
            ],
            ['Accept' => 'application/json'],
        );

        $response->assertOk()->assertJson(['status' => 'running']);

        $spy->shouldHaveReceived('handle')->once();

        expect(file_get_contents($userModelPath))
            ->toContain('use Spatie\Permission\Traits\HasRoles;')
            ->toContain('HasRoles')
            ->not->toContain('LoginAuditgable')
            ->not->toContain('Capell\Admin\Traits');
    } finally {
        if (is_dir(base_path('app'))) {
            exec('rm -rf ' . escapeshellarg(base_path('app')));
        }
    }
});

it('returns failed status when a step throws', function (): void {
    $installId = '33333333-3333-4333-a333-333333333333';
    $inputData = new InstallInputData(
        siteUrl: 'https://example.com',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        newUser: new NewUserData(name: 'Admin', email: 'admin@example.com', password: 'password123'),
    );
    $plan = InstallPlan::build($inputData);

    Cache::put(sprintf('capell.install.%s.input', $installId), $inputData->toArray());
    Cache::put(sprintf('capell.install.%s.plan', $installId), $plan);
    Cache::put(sprintf('capell.install.%s.current_step', $installId), InstallPlan::STEP_PREPARE_ENVIRONMENT);
    Cache::put(sprintf('capell.install.%s.completed_steps', $installId), [InstallPlan::STEP_PREFLIGHT_CHECKS]);

    RunInstallStepAction::mock()
        ->shouldReceive('handle')
        ->once()
        ->andThrow(new RuntimeException('Step exploded'));

    $response = withSession(installerAccessSessionData($installId))->post(
        route('capell-installer.run-step'),
        [
            'install_id' => $installId,
            'step' => InstallPlan::STEP_PREPARE_ENVIRONMENT,
        ],
        ['Accept' => 'application/json'],
    );

    $response->assertOk();
    $response->assertJson([
        'installId' => $installId,
        'status' => 'failed',
        'error' => 'Step exploded',
    ]);
});

it('persists a resolved user id returned by an install step', function (): void {
    $installId = '33333333-3333-4333-a333-333333333334';
    $inputData = new InstallInputData(
        siteUrl: 'https://example.com',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        newUser: new NewUserData(name: 'Admin', email: 'admin@example.com', password: 'password123'),
    );
    $plan = InstallPlan::build($inputData);

    Cache::put(sprintf('capell.install.%s.input', $installId), $inputData->toArray());
    Cache::put(sprintf('capell.install.%s.plan', $installId), $plan);
    Cache::put(sprintf('capell.install.%s.current_step', $installId), InstallPlan::STEP_PREPARE_ENVIRONMENT);
    Cache::put(sprintf('capell.install.%s.completed_steps', $installId), [InstallPlan::STEP_PREFLIGHT_CHECKS]);

    RunInstallStepAction::mock()
        ->shouldReceive('handle')
        ->once()
        ->andReturn(123);

    withSession(installerAccessSessionData($installId))->post(
        route('capell-installer.run-step'),
        [
            'install_id' => $installId,
            'step' => InstallPlan::STEP_PREPARE_ENVIRONMENT,
        ],
        ['Accept' => 'application/json'],
    )->assertOk();

    expect(Cache::get(sprintf('capell.install.%s.user_id', $installId)))->toBe(123);
});

it('returns validation errors when run-step payload is malformed', function (): void {
    post(
        route('capell-installer.run-step'),
        ['install_id' => 'not-a-uuid'],
        ['Accept' => 'application/json'],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['install_id', 'step']);
});

it('returns json validation errors for malformed run-step payloads without an accept header', function (): void {
    post(route('capell-installer.run-step'), ['install_id' => 'not-a-uuid'])
        ->assertUnprocessable()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonValidationErrors(['install_id', 'step']);
});

it('returns 410 when run-step is called for an unknown install', function (): void {
    $installId = '44444444-4444-4444-a444-444444444444';

    withSession(installerAccessSessionData($installId))->post(
        route('capell-installer.run-step'),
        [
            'install_id' => $installId,
            'step' => InstallPlan::STEP_PREPARE_ENVIRONMENT,
        ],
        ['Accept' => 'application/json'],
    )
        ->assertGone()
        ->assertJson(['status' => 'failed']);
});

it('returns 404 when run-step is called without install session access', function (): void {
    $installId = '44444444-4444-4444-a444-555555555555';
    Cache::put(sprintf('capell.install.%s.input', $installId), ['site_url' => 'https://example.com']);

    post(
        route('capell-installer.run-step'),
        [
            'install_id' => $installId,
            'step' => InstallPlan::STEP_PREPARE_ENVIRONMENT,
        ],
        ['Accept' => 'application/json'],
    )->assertNotFound();
});

it('returns complete status after the last step', function (): void {
    $installId = '55555555-5555-4555-a555-555555555555';
    $inputData = new InstallInputData(
        siteUrl: 'https://example.com',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        newUser: new NewUserData(name: 'Admin', email: 'admin@example.com', password: 'password123'),
    );
    $plan = InstallPlan::build($inputData);
    $lastPlanStep = end($plan);
    assert(is_array($lastPlanStep));
    $lastStep = $lastPlanStep['key'];

    Cache::put(sprintf('capell.install.%s.input', $installId), $inputData->toArray());
    Cache::put(sprintf('capell.install.%s.plan', $installId), $plan);
    Cache::put(sprintf('capell.install.%s.current_step', $installId), $lastStep);
    Cache::put(sprintf('capell.install.%s.completed_steps', $installId), array_column(array_slice($plan, 0, -1), 'key'));

    RunInstallStepAction::spy();

    withSession(installerAccessSessionData($installId))->post(
        route('capell-installer.run-step'),
        [
            'install_id' => $installId,
            'step' => $lastStep,
        ],
        ['Accept' => 'application/json'],
    )
        ->assertOk()
        ->assertJson([
            'installId' => $installId,
            'currentStep' => $lastStep,
            'nextStep' => null,
            'status' => 'complete',
        ]);
});

// ─── Validation ──────────────────────────────────────────────────────────────

it('rejects missing required fields', function (): void {
    post(route('capell-installer.store'), [])
        ->assertSessionHasErrors(['site_url', 'language', 'new_user_name', 'new_user_email', 'new_user_password']);
});

it('uses configured admin user defaults when create-admin fields are omitted', function (): void {
    config()->set('capell-installer.admin_user', [
        'name' => 'Comfy Admin',
        'email' => 'comfy-admin@example.com',
        'password' => 'comfy-password',
    ]);

    $spy = RunInstallAction::spy();

    post(route('capell-installer.store'), [
        'site_url' => 'https://example.com',
        'language' => 'en',
        'package_selection_mode' => 'custom',
        'packages' => [],
        'seed_default_data' => '1',
    ])->assertRedirect();

    $spy->shouldHaveReceived('handle')->once()->withArgs(
        fn (InstallInputData $input, ProgressReporter $reporter): bool => $input->newUser instanceof NewUserData
            && $input->newUser->name === 'Comfy Admin'
            && $input->newUser->email === 'comfy-admin@example.com'
            && $input->newUser->password === 'comfy-password',
    );
});

it('rejects passwords shorter than 8 chars', function (): void {
    post(route('capell-installer.store'), installPostPayload(['new_user_password' => 'short']))
        ->assertSessionHasErrors(['new_user_password']);
});

it('rejects duplicate email addresses when creating a new admin user', function (): void {
    User::factory()->createOne(['email' => 'taken@example.com']);

    post(route('capell-installer.store'), installPostPayload([
        'new_user_email' => 'taken@example.com',
    ]))
        ->assertSessionHasErrors(['new_user_email']);
});

it('returns duplicate email errors as json for ajax installs', function (): void {
    User::factory()->createOne(['email' => 'taken@example.com']);

    post(
        route('capell-installer.store'),
        installPostPayload(['new_user_email' => 'taken@example.com']),
        ['Accept' => 'application/json'],
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['new_user_email']);
});

it('requires a valid existing user when using an existing admin account', function (): void {
    post(route('capell-installer.store'), installPostPayload([
        'admin_user_mode' => 'existing',
        'existing_user_id' => '999999',
        'new_user_name' => null,
        'new_user_email' => null,
        'new_user_password' => null,
    ]))
        ->assertSessionHasErrors(['existing_user_id']);
});

it('does not require an example role user password when using an existing admin account', function (): void {
    $user = User::factory()->createOne();

    post(route('capell-installer.store'), installPostPayload([
        'admin_user_mode' => 'existing',
        'existing_user_id' => $user->getKey(),
        'new_user_name' => null,
        'new_user_email' => null,
        'new_user_password' => null,
        'create_role_users' => '1',
        'role_user_password' => null,
    ]))
        ->assertSessionDoesntHaveErrors(['role_user_password']);
});

// ─── Progress endpoint ───────────────────────────────────────────────────────

it('returns json status from the progress data endpoint', function (): void {
    $installId = '22222222-2222-4222-a222-333333333333';
    Cache::put(sprintf('capell.install.%s.status', $installId), 'running');

    withSession(installerAccessSessionData($installId))
        ->get(route('capell-installer.progress.data', ['installId' => $installId]))
        ->assertOk()
        ->assertJson([
            'installId' => $installId,
            'status' => 'running',
            'lines' => [],
        ]);
});

it('clears terminal progress locks and hides stale success data for failed installs', function (): void {
    $installId = '22222222-2222-4222-a222-333333333334';
    Cache::put(InstallerSessionRepository::LOCK_KEY, ['installId' => $installId]);
    Cache::put(sprintf('capell.install.%s.status', $installId), 'failed');
    Cache::put(sprintf('capell.install.%s.success', $installId), ['primaryAdmin' => 'Admin <admin@example.com>']);
    Cache::put(sprintf('capell.install.%s.output', $installId), json_encode(['line' => 'Boom']) . PHP_EOL);

    withSession(installerAccessSessionData($installId))
        ->get(route('capell-installer.progress.data', ['installId' => $installId]))
        ->assertOk()
        ->assertJson([
            'installId' => $installId,
            'status' => 'failed',
        ]);

    expect(Cache::get(InstallerSessionRepository::LOCK_KEY))->toBeNull()
        ->and(Cache::get(sprintf('capell.install.%s.success', $installId)))->toBeNull();
});

it('returns the success redirect when progress data reaches complete', function (): void {
    $installId = '22222222-2222-4222-a222-333333333335';
    Cache::put(InstallerSessionRepository::LOCK_KEY, ['installId' => $installId]);
    Cache::put(sprintf('capell.install.%s.status', $installId), 'complete');

    withSession(installerAccessSessionData($installId))
        ->get(route('capell-installer.progress.data', ['installId' => $installId]))
        ->assertOk()
        ->assertJson([
            'installId' => $installId,
            'status' => 'complete',
            'redirectUrl' => route('capell-installer.success', ['installId' => $installId]),
        ]);

    expect(Cache::get(InstallerSessionRepository::LOCK_KEY))->toBeNull();
});

it('blocks install progress, report, success, and cancel routes without install session access', function (): void {
    $installId = '22222222-2222-4222-a222-444444444444';
    Cache::put('capell.install.lock', ['installId' => $installId]);
    Cache::put(sprintf('capell.install.%s.status', $installId), 'running');
    Cache::put(sprintf('capell.install.%s.success', $installId), [
        'primaryAdmin' => 'Ben Johnson <ben@example.com>',
        'roleUsersCreated' => true,
    ]);

    get(route('capell-installer.progress', ['installId' => $installId]))
        ->assertNotFound();

    get(route('capell-installer.progress.data', ['installId' => $installId]))
        ->assertNotFound();

    get(route('capell-installer.progress.download', ['installId' => $installId]))
        ->assertNotFound()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonPath('error', 'Install report not found.');

    get(route('capell-installer.success', ['installId' => $installId]))
        ->assertNotFound();

    post(route('capell-installer.cancel', ['installId' => $installId]))
        ->assertNotFound();

    expect(Cache::get('capell.install.lock'))->toBe(['installId' => $installId])
        ->and(Cache::get(sprintf('capell.install.%s.success', $installId)))->not->toBeNull();
});

// ─── CSRF token in responses ─────────────────────────────────────────────────

it('includes a csrfToken in every run-step response', function (): void {
    $installId = '66666666-6666-4666-a666-666666666666';
    $inputData = new InstallInputData(
        siteUrl: 'https://example.com',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        newUser: new NewUserData(name: 'Admin', email: 'admin@example.com', password: 'password123'),
    );
    $plan = InstallPlan::build($inputData);

    Cache::put(sprintf('capell.install.%s.input', $installId), $inputData->toArray());
    Cache::put(sprintf('capell.install.%s.plan', $installId), $plan);
    Cache::put(sprintf('capell.install.%s.current_step', $installId), InstallPlan::STEP_PREPARE_ENVIRONMENT);
    Cache::put(sprintf('capell.install.%s.completed_steps', $installId), [InstallPlan::STEP_PREFLIGHT_CHECKS]);

    RunInstallStepAction::spy();

    $response = withSession(installerAccessSessionData($installId))->post(
        route('capell-installer.run-step'),
        ['install_id' => $installId, 'step' => InstallPlan::STEP_PREPARE_ENVIRONMENT],
        ['Accept' => 'application/json'],
    );

    $response->assertOk()->assertJsonStructure(['csrfToken']);

    expect($response->json('csrfToken'))->toBeString()->not->toBeEmpty();
});

it('cancel clears all cache keys and the lock', function (): void {
    $installId = 'cccccc00-cccc-4ccc-cccc-cccccccccccc';

    Cache::put('capell.install.lock', ['installId' => $installId]);
    foreach (installerSessionSuffixes() as $suffix) {
        Cache::put(sprintf('capell.install.%s.%s', $installId, $suffix), 'value');
    }

    withSession(installerAccessSessionData($installId))
        ->post(route('capell-installer.cancel', ['installId' => $installId]))
        ->assertRedirect(route('capell-installer.show'));

    expect(Cache::get('capell.install.lock'))->toBeNull();

    foreach (installerSessionSuffixes() as $suffix) {
        expect(Cache::get(sprintf('capell.install.%s.%s', $installId, $suffix)))->toBeNull();
    }
});

it('cancel returns json for ajax requests', function (): void {
    $installId = 'dddddd00-dddd-4ddd-dddd-dddddddddddd';

    Cache::put('capell.install.lock', ['installId' => $installId]);

    withSession(installerAccessSessionData($installId))->post(
        route('capell-installer.cancel', ['installId' => $installId]),
        [],
        ['Accept' => 'application/json'],
    )->assertOk()->assertJson(['status' => 'cancelled']);
});

it('cancel returns 404 for an invalid uuid', function (): void {
    post(route('capell-installer.cancel', ['installId' => 'not-a-uuid']))
        ->assertNotFound();
});

it('cancel does not clear another install lock', function (): void {
    $installId = 'eeeeee00-eeee-4eee-eeee-eeeeeeeeeeee';
    $otherInstallId = 'ffffff00-ffff-4fff-ffff-ffffffffffff';

    Cache::put('capell.install.lock', ['installId' => $otherInstallId]);
    Cache::put(sprintf('capell.install.%s.status', $otherInstallId), 'running');

    withSession(installerAccessSessionData($installId))
        ->post(route('capell-installer.cancel', ['installId' => $installId]))
        ->assertRedirect(route('capell-installer.show'));

    expect(Cache::get('capell.install.lock'))->toBe(['installId' => $otherInstallId]);
});
