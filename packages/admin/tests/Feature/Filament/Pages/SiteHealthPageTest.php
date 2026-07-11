<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Diagnostics\SiteAwareSiteHealthReportExtender;
use Capell\Admin\Contracts\Diagnostics\SiteHealthReportExtender;
use Capell\Admin\Data\Diagnostics\DiagnosticCheckData;
use Capell\Admin\Data\Diagnostics\DiagnosticSectionData;
use Capell\Admin\Filament\Pages\SiteHealthPage;
use Capell\Admin\Support\Diagnostics\ExtensionHealthSiteHealthWidget;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Livewire;

use function Pest\Laravel\get;

use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;

uses(CreatesAdminUser::class)->group('diagnostics');

function grantSiteHealthPageAccess(): void
{
    Permission::create(['name' => 'View:SiteHealthPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SiteHealthPage');
}

function fakeOptimizerRuntimeProcesses(): void
{
    $nodeProcess = Mockery::mock(Process::class);
    $nodeProcess->shouldReceive('run')->atLeast()->once();
    $nodeProcess->shouldReceive('isSuccessful')->atLeast()->once()->andReturnTrue();
    $nodeProcess->shouldReceive('getOutput')->atLeast()->once()->andReturn("v22.0.0\n");

    $playwrightProcess = Mockery::mock(Process::class);
    $playwrightProcess->shouldReceive('run')->atLeast()->once();
    $playwrightProcess->shouldReceive('isSuccessful')->atLeast()->once()->andReturnTrue();
    $playwrightProcess->shouldReceive('getOutput')->atLeast()->once()->andReturn("Version 1.52.0\n");

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->with(['node', '--version'], base_path())->atLeast()->once()->andReturn($nodeProcess);
    $factory->shouldReceive('make')->with(['npx', 'playwright', '--version'], base_path())->atLeast()->once()->andReturn($playwrightProcess);
    app()->instance(ProcessFactoryInterface::class, $factory);
}

function createSiteScopedHealthUser(Site $site): Authenticatable
{
    $user = new class extends Authenticatable implements FilamentUser
    {
        /** @use HasFactory<Factory<self>> */
        use HasFactory;

        public int $siteId;

        public function canAccessPanel(Panel $panel): bool
        {
            return true;
        }

        // @phpstan-ignore missingType.iterableValue
        public function can(mixed $abilities, mixed $arguments = []): bool
        {
            return $abilities === 'View:SiteHealthPage';
        }

        public function isGlobalAdmin(): bool
        {
            return false;
        }

        /** @return SupportCollection<int, int> */
        public function getAssignedSiteIds(): SupportCollection
        {
            return collect([$this->siteId]);
        }
    };
    $user->siteId = $site->getKey();

    $user->forceFill([
        'name' => 'Site Health Scoped User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    return $user;
}

it('uses site health navigation labels', function (): void {
    expect(SiteHealthPage::getNavigationLabel())->toBe(__('capell-admin::navigation.site_health'))
        ->and(resolve(SiteHealthPage::class)->getTitle())->toBe(__('capell-admin::generic.site_health'))
        ->and(resolve(SiteHealthPage::class)->getSubheading())->toBe(__('capell-admin::generic.site_health_info'));
});

it('can not render site health page without permission', function (): void {
    test()->actingAsUser();

    get(SiteHealthPage::getUrl())->assertForbidden();
});

it('renders site health diagnostics for authorized admins', function (): void {
    grantSiteHealthPageAccess();
    fakeOptimizerRuntimeProcesses();

    Site::factory()->createOne();

    Livewire::test(SiteHealthPage::class)
        ->assertSuccessful()
        ->assertSee(__('capell-admin::generic.site_health_site'))
        ->assertSee(__('capell-admin::generic.site_health_site_helper'))
        ->assertSee(__('capell-admin::generic.site_health_optimizer'))
        ->assertSee(__('capell-admin::generic.site_health_environment'));
});

it('shows setup guidance when no sites are available for health checks', function (): void {
    grantSiteHealthPageAccess();
    fakeOptimizerRuntimeProcesses();

    Livewire::test(SiteHealthPage::class)
        ->assertSuccessful()
        ->assertSet('selectedSiteId', null)
        ->assertSee(__('capell-admin::generic.no_sites'))
        ->assertSee(__('capell-admin::generic.site_health_no_sites_description'));
});

it('shows the clean extension health state on the developer dashboard', function (): void {
    grantSiteHealthPageAccess();
    fakeOptimizerRuntimeProcesses();

    expect(collect(resolve(SiteHealthPage::class)->siteHealthWidgets())
        ->contains(fn (mixed $widget): bool => $widget instanceof ExtensionHealthSiteHealthWidget))->toBeTrue();

    Livewire::test(SiteHealthPage::class)
        ->assertSuccessful()
        ->assertSee(__('capell-admin::dashboard.extensions_health_all_good'));
});

it('normalises tampered site health selections before report extenders receive them', function (): void {
    $assignedSite = Site::factory()->createOne(['name' => 'Assigned site']);
    $otherSite = Site::factory()->createOne(['name' => 'Other site']);
    test()->actingAs(createSiteScopedHealthUser($assignedSite));
    fakeOptimizerRuntimeProcesses();

    app()->bind('tests.site-health-extender', fn (): SiteHealthReportExtender => new class implements SiteAwareSiteHealthReportExtender
    {
        /** @return list<DiagnosticSectionData> */
        public function sections(): array
        {
            return $this->sectionsForSite(null);
        }

        /** @return list<DiagnosticSectionData> */
        public function sectionsForSite(?int $siteId): array
        {
            return [
                new DiagnosticSectionData(
                    label: 'Scoped diagnostics',
                    checks: [
                        new DiagnosticCheckData(
                            status: 'green',
                            label: 'Selected site',
                            detail: 'Site ' . $siteId,
                        ),
                    ],
                ),
            ];
        }
    });
    app()->tag('tests.site-health-extender', SiteHealthReportExtender::TAG);

    Livewire::test(SiteHealthPage::class)
        ->set('selectedSiteId', $otherSite->getKey())
        ->assertSet('selectedSiteId', $assignedSite->getKey())
        ->assertSee('Site ' . $assignedSite->getKey())
        ->assertDontSee('Site ' . $otherSite->getKey());
});

it('uses configured super admin role names for global site health access', function (): void {
    config()->set('capell.roles.super_admin', 'platform-admin');

    $user = new class extends Authenticatable
    {
        /** @use HasFactory<Factory<self>> */
        use HasFactory;

        public function hasRole(string $role): bool
        {
            return $role === 'platform-admin';
        }
    };

    expect(SiteScope::isGlobalActor($user))->toBeTrue();
});

it('shows site domain context in site health site options', function (): void {
    grantSiteHealthPageAccess();

    $site = Site::factory()->createOne(['name' => 'London']);
    SiteDomain::factory()->createOne([
        'site_id' => $site->getKey(),
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => '/uk',
        'default' => true,
    ]);

    expect(resolve(SiteHealthPage::class)->siteOptions()[$site->getKey()])
        ->toBe('London (https://example.test/uk)');
});
