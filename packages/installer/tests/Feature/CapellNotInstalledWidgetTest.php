<?php

declare(strict_types=1);

use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Core\Models\Site;
use Capell\Core\Support\Extensions\InstalledExtensionRepository;
use Capell\Installer\Filament\Pages\InstallCapellPage;
use Capell\Installer\Filament\Pages\InstallGuidePage;
use Capell\Installer\Filament\Pages\InstallProgressPage;
use Capell\Installer\Filament\Widgets\CapellNotInstalledFilamentWidget;
use Capell\Installer\Livewire\InstallerWarning;
use Capell\Installer\Providers\InstallerServiceProvider;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Lazy;
use Livewire\Livewire;

use function Pest\Laravel\get;

uses(CreatesAdminUser::class)->group('installer');

beforeEach(function (): void {
    Site::query()->delete();
});

function fakeInstallerComposerAvailability(array $unavailablePackageNames = []): void
{
    app()->instance(InstalledExtensionRepository::class, new readonly class($unavailablePackageNames)
    {
        /** @param  array<int, string>  $unavailablePackageNames */
        public function __construct(private array $unavailablePackageNames) {}

        public function isAvailable(string $composerName, ?string $path = null): bool
        {
            return ! in_array($composerName, $this->unavailablePackageNames, true);
        }

        public function has(string $composerName): bool
        {
            return false;
        }
    });
}

it('exposes the installer route URL via the widget', function (): void {
    $widget = new CapellNotInstalledFilamentWidget;

    expect($widget->installerUrl())->toBe(route('capell-installer.show'));
});

it('is registered as a not-installed dashboard Filament widget', function (): void {
    expect(CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::NotInstalled))
        ->toContain(CapellNotInstalledFilamentWidget::class);
});

it('registers the install guide page with the admin panel', function (): void {
    expect(CapellAdmin::getAdminSurfaceRegistry()->pages())
        ->toContain(InstallCapellPage::class)
        ->toContain(InstallGuidePage::class)
        ->toContain(InstallProgressPage::class);
});

it('renders the Filament installer page', function (): void {
    test()->actingAsAdmin();

    Livewire::test(InstallCapellPage::class)
        ->assertSee(__('capell-installer::widgets.install_action'))
        ->assertSee(route('capell-installer.store'), false);
});

it('uses a human title for the install guide page', function (): void {
    $page = new InstallGuidePage;

    expect($page->getTitle())->toBe('Install Guide')
        ->and($page->getHeading())->toBe('Install Guide');
});

it('adds the install guide to navigation when Capell is not installed', function (): void {
    test()->actingAsAdmin();

    expect(InstallGuidePage::shouldRegisterNavigation())->toBeTrue();
});

it('removes the install guide from navigation when Capell is installed', function (): void {
    test()->actingAsAdmin();

    Site::factory()->createOne();

    expect(InstallGuidePage::shouldRegisterNavigation())->toBeFalse();
});

it('is visible when no sites exist', function (): void {
    expect(CapellNotInstalledFilamentWidget::canView())->toBeTrue();
});

it('is visible when the admin package is not composer available even if sites exist', function (): void {
    fakeInstallerComposerAvailability([AdminServiceProvider::$packageName]);
    Site::factory()->createOne();

    expect(CapellNotInstalledFilamentWidget::canView())->toBeTrue();
});

it('is hidden when the package is installed and sites exist', function (): void {
    Site::factory()->createOne();

    expect(CapellNotInstalledFilamentWidget::canView())->toBeFalse();
});

it('has no settings key', function (): void {
    expect(CapellNotInstalledFilamentWidget::settingsKey())->toBe('');
});

it('has no role restrictions', function (): void {
    expect(CapellNotInstalledFilamentWidget::rolesConfigKeys())->toBe([]);
});

it('renders the not-installed heading and message', function (): void {
    test()->actingAsAdmin();

    Livewire::test(CapellNotInstalledFilamentWidget::class)
        ->assertSee(__('capell-installer::widgets.not_installed_heading'))
        ->assertSee(__('capell-installer::widgets.not_installed_message'))
        ->assertSee(__('capell-installer::widgets.install_action'));
});

it('renders a link to the install route', function (): void {
    test()->actingAsAdmin();

    Livewire::test(CapellNotInstalledFilamentWidget::class)
        ->assertSee(route('capell-installer.show'));
});

it('lazy mounts the security warning when Capell is installed and installer remains installed', function (): void {
    test()->actingAsAdmin();
    Site::factory()->createOne();

    get(CapellDashboard::getUrl())
        ->assertOk()
        ->assertSee('installer-warning', false)
        ->assertSee('x-intersect', false)
        ->assertDontSee('confirm(', false);
});

it('renders the installer delete action with reinstall guidance', function (): void {
    test()->actingAsAdmin();
    Livewire::withoutLazyLoading();

    Site::factory()->createOne();

    Livewire::test(InstallerWarning::class)
        ->assertSee(__('capell-installer::widgets.installer_installed_heading'))
        ->assertSee(__('capell-installer::widgets.installer_installed_message'))
        ->assertSee(__('capell-installer::widgets.reinstall_action'))
        ->assertSee(route('capell-installer.show'))
        ->assertSee(__('capell-installer::widgets.delete_installer_action'));

    $deleteInstallerAction = (new InstallerWarning)->deleteInstallerAction();
    $modalContent = $deleteInstallerAction->getModalContent();
    $modalContentHtml = $modalContent instanceof View ? $modalContent->render() : filamentText($modalContent);

    expect($deleteInstallerAction->isConfirmationRequired())->toBeTrue()
        ->and($deleteInstallerAction->getModalHeading())->toBe(__('capell-installer::widgets.delete_installer_modal_heading'))
        ->and($modalContentHtml)
        ->toContain(__('capell-installer::widgets.delete_installer_reinstall_message'))
        ->toContain(__('capell-installer::widgets.delete_installer_reinstall_link'));
});

it('lazy loads the installer warning component', function (): void {
    $lazyAttributes = new ReflectionClass(InstallerWarning::class)->getAttributes(Lazy::class);

    expect($lazyAttributes)->not->toBeEmpty();
});

it('renders install progress output and report links from cached installer state', function (): void {
    expect(InstallProgressPage::canAccess())->toBeFalse()
        ->and(InstallProgressPage::shouldRegisterNavigation())->toBeFalse();

    test()->actingAsAdmin();

    cache()->put('capell.install.install-123.status', 'running');
    cache()->put('capell.install.install-123.output', implode("\n", [
        json_encode(['message' => 'Preparing database'], JSON_THROW_ON_ERROR),
        json_encode(['line' => 'Publishing migrations'], JSON_THROW_ON_ERROR),
        'Plain install output',
    ]));

    $page = new InstallProgressPage;
    $page->mount('install-123');

    expect(InstallProgressPage::canAccess())->toBeTrue()
        ->and($page->getTitle())->toBe(__('capell-installer::installer.progress_heading'))
        ->and($page->installId)->toBe('install-123')
        ->and($page->installStatus)->toBe('running')
        ->and($page->lines())->toBe([
            'Preparing database',
            'Publishing migrations',
            'Plain install output',
        ])
        ->and($page->progressDataUrl())->toBe(route('capell-installer.progress.data', ['installId' => 'install-123']))
        ->and($page->reportUrl())->toBe(route('capell-installer.progress.download', ['installId' => 'install-123']))
        ->and($page->reportDownloadFilename())->toBe('capell-install-install-123.json');
});

it('shows the installer alert before Capell is installed', function (): void {
    test()->actingAsAdmin();

    expect(view('capell-installer::components.installer-warning')->render())
        ->toContain(__('capell-installer::widgets.not_installed_heading'))
        ->toContain(__('capell-installer::widgets.install_action'))
        ->toContain(__('capell-installer::widgets.install_guide_action'));
});

it('shows active install progress data in the installer alert', function (): void {
    test()->actingAsAdmin();
    Livewire::withoutLazyLoading();

    $installId = '00000000-0000-4000-8000-000000000000';

    fakeInstallerComposerAvailability([AdminServiceProvider::$packageName]);
    Cache::put('capell.install.lock', ['installId' => $installId, 'queued' => true], 7200);
    Cache::put(sprintf('capell.install.%s.status', $installId), 'running', 7200);
    Cache::put(sprintf('capell.install.%s.plan', $installId), ['prepare', 'install'], 7200);

    Livewire::test(InstallerWarning::class)
        ->assertSee(__('capell-installer::widgets.not_installed_heading'))
        ->assertSee(__('capell-installer::widgets.active_install_heading'))
        ->assertSee(__('capell-installer::widgets.active_install_action'))
        ->assertSee(route('capell-installer.progress', ['installId' => $installId]))
        ->assertSee(__('capell-installer::widgets.active_install_details', [
            'installId' => '00000000',
            'status' => __('capell-installer::installer.status_running'),
            'steps' => 2,
        ]));
});

it('hides the security warning after installer has been removed', function (): void {
    test()->actingAsAdmin();
    fakeInstallerComposerAvailability([InstallerServiceProvider::$packageName]);
    Site::factory()->createOne();

    get(CapellDashboard::getUrl())
        ->assertOk()
        ->assertDontSee(__('capell-installer::widgets.installer_installed_heading'));
});

it('is the only widget returned by the dashboard when no sites exist', function (): void {
    $page = new CapellDashboard;

    expect($page->getWidgets())->toContain(CapellNotInstalledFilamentWidget::class);
});

it('appears on the dashboard page when no sites exist', function (): void {
    test()->actingAsAdmin();

    get(CapellDashboard::getUrl())
        ->assertOk()
        ->assertSeeLivewire(CapellNotInstalledFilamentWidget::class);
});
