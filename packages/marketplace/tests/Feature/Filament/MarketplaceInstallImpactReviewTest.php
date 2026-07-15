<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Core\Actions\Packages\BuildExtensionInstallImpactAction;
use Capell\Core\Data\PackageCapabilityGraphData;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Marketplace\Filament\Livewire\MarketplaceExtensionsBrowser;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('builds a typed direct and transitive install impact graph', function (): void {
    $dependency = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/workflow-runtime',
        overrides: [
            'version' => '2.0.0-beta.1',
            'contributes' => [
                ['type' => 'route', 'class' => 'Vendor\\Runtime\\Routes'],
                ['type' => 'scheduled-job', 'class' => 'Vendor\\Runtime\\CleanupJob'],
            ],
            'database' => ['migrations' => true, 'settings' => false, 'requiredTables' => ['workflow_runs']],
            'permissions' => ['workflow.review'],
            'capabilities' => ['private-storage'],
        ],
    ));
    $selected = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/editorial-suite',
        overrides: [
            'version' => '3.0.0',
            'dependencies' => ['requires' => [$dependency->name], 'supports' => [], 'conflicts' => []],
        ],
    ));
    $graph = new PackageCapabilityGraphData([], []);

    $impact = BuildExtensionInstallImpactAction::run(
        $selected,
        $graph,
        [$dependency->name => $dependency],
        [
            $selected->name => ['entitlement' => 'licensed', 'current_version' => '2.5.0'],
            $dependency->name => ['entitlement' => 'included'],
        ],
    );

    expect($impact->dependencyNodes)->toHaveCount(2)
        ->and($impact->dependencyNodes[0]->direct)->toBeTrue()
        ->and($impact->dependencyNodes[0]->changeOperation)->toBe('update')
        ->and($impact->dependencyNodes[1]->direct)->toBeFalse()
        ->and($impact->dependencyNodes[1]->maturity)->toBe('beta')
        ->and($impact->dependencyNodes[1]->migrations)->toContain('migrations', 'required-table:workflow_runs')
        ->and($impact->dependencyNodes[1]->routes)->toBe(['Vendor\\Runtime\\Routes'])
        ->and($impact->dependencyNodes[1]->scheduledJobs)->toBe(['Vendor\\Runtime\\CleanupJob'])
        ->and($impact->dependencyNodes[1]->storage)->toBe(['private-storage'])
        ->and($impact->dependencyNodes[1]->permissions)->toBe(['workflow.review']);
});

it('renders complete impact while preserving beta dependency acknowledgement', function (): void {
    Permission::create(['name' => 'View:ExtensionsPage', 'guard_name' => 'web']);
    Permission::create(['name' => ExtensionsPage::MANAGE_PERMISSION, 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(['View:ExtensionsPage', ExtensionsPage::MANAGE_PERMISSION]);

    $selected = marketplaceImpactPayload([
        'slug' => 'editorial-suite',
        'name' => 'Editorial Suite',
        'composer_name' => 'capell-app/editorial-suite',
        'dependencies' => ['requires' => ['capell-app/workflow-runtime']],
        'maturity' => 'stable',
        'maturity_label' => 'Released',
    ]);
    $dependency = marketplaceImpactPayload([
        'slug' => 'workflow-runtime',
        'name' => 'Workflow Runtime',
        'composer_name' => 'capell-app/workflow-runtime',
        'maturity' => 'beta',
        'maturity_label' => 'Beta',
        'entitlement' => 'included',
        'install_impact' => [
            'migrations' => ['workflow_runs'],
            'routes' => ['/workflow'],
            'scheduled_jobs' => ['CleanupJob'],
            'storage' => ['private'],
            'permissions' => ['workflow.review'],
        ],
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/by-composer*' => Http::response(['data' => [$selected, $dependency]]),
        'https://marketplace.test/api/extensions?*' => Http::response([
            'data' => [$selected],
            'links' => ['next' => null],
        ]),
    ]);
    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    Livewire::test(MarketplaceExtensionsBrowser::class)
        ->call('loadMarketplaceResults')
        ->set('selectedMarketplaceComposerNames', ['capell-app/editorial-suite'])
        ->call('showMarketplaceInstallReview')
        ->assertSee(__('capell-marketplace::marketplace.selection.complete_impact_heading'))
        ->assertSee('Editorial Suite')
        ->assertSee('Workflow Runtime')
        ->assertSee('Required transitively')
        ->assertSee('workflow_runs')
        ->assertSee('workflow.review')
        ->assertSee(__('capell-marketplace::marketplace.selection.beta_acknowledgement_label'))
        ->assertSee('capell-app/workflow-runtime');
});

/** @param array<string, mixed> $overrides */
function marketplaceImpactPayload(array $overrides): array
{
    return [
        'slug' => 'impact-extension',
        'name' => 'Impact Extension',
        'composer_name' => 'capell-app/impact-extension',
        'kind' => 'package',
        'description' => 'Impact fixture.',
        'price_cents' => 0,
        'is_paid' => false,
        'latest_version' => '1.0.0',
        'catalogue_role' => 'extension',
        'maturity' => 'stable',
        'maturity_label' => 'Released',
        'included_with_capell_all' => false,
        ...$overrides,
    ];
}
