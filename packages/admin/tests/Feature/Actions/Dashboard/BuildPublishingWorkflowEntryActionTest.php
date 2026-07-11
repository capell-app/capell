<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildPublishingWorkflowEntryAction;
use Capell\Admin\Tests\Fixtures\Autoload\TestPublishingWorkflowAttentionContribution;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('builds the publishing workflow entry from an installed workflow attention contribution', function (): void {
    Permission::create(['name' => 'View:PublishingWorkflowPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:PublishingWorkflowPage');

    registerPublishingWorkflowManifest('capell-app/publishing-studio', [
        [
            'type' => 'workflow-attention',
            'class' => TestPublishingWorkflowAttentionContribution::class,
            'label' => 'Publishing workflow',
            'description' => 'Review publishing work that needs attention.',
            'actionLabel' => 'Open workflow',
        ],
    ]);

    $entry = BuildPublishingWorkflowEntryAction::run(test()->authenticatedUser());

    expect($entry)->not->toBeNull()
        ->and($entry->label)->toBe('Publishing workflow')
        ->and($entry->description)->toBe('Review publishing work that needs attention.')
        ->and($entry->actionLabel)->toBe('Open workflow')
        ->and($entry->url)->toBe('/admin/publishing-studio/workflow')
        ->and($entry->count)->toBe(3);
});

it('skips workflow entries when the current user cannot access the contribution', function (): void {
    test()->actingAsUser();

    registerPublishingWorkflowManifest('capell-app/publishing-studio', [
        [
            'type' => 'workflow-attention',
            'class' => TestPublishingWorkflowAttentionContribution::class,
            'label' => 'Publishing workflow',
        ],
    ]);

    expect(BuildPublishingWorkflowEntryAction::run(test()->authenticatedUser()))->toBeNull();
});

it('uses metadata counts and management URLs when no attention provider returns items', function (): void {
    test()->actingAsAdmin();

    registerPublishingWorkflowManifest('vendor/editorial-tools', [
        [
            'type' => 'dashboard-widget',
            'class' => 'Vendor\\EditorialTools\\Widgets\\IgnoredWidget',
        ],
        [
            'type' => 'workflow-attention',
            'class' => 'Vendor\\EditorialTools\\Workflow\\MissingProvider',
            'label' => 'Editorial tools',
            'description' => 'Metadata-backed workflow summary.',
            'actionLabel' => 'Review editorial tools',
            'managementUrl' => '/admin/editorial-tools',
            'count' => '7',
        ],
    ]);

    $entry = BuildPublishingWorkflowEntryAction::run(test()->authenticatedUser());

    expect($entry)->not->toBeNull()
        ->and($entry->label)->toBe('Editorial tools')
        ->and($entry->description)->toBe('Metadata-backed workflow summary.')
        ->and($entry->actionLabel)->toBe('Review editorial tools')
        ->and($entry->url)->toBe('/admin/editorial-tools')
        ->and($entry->count)->toBe(7);
});

it('falls back to named management routes and ignores uninstalled packages', function (): void {
    test()->actingAsAdmin();
    Route::get('/admin/workflow-route', fn (): string => 'workflow')->name('workflow.route');
    resolve(Router::class)->getRoutes()->refreshNameLookups();

    registerPublishingWorkflowManifest('vendor/uninstalled-tools', [
        [
            'type' => 'workflow-attention',
            'label' => 'Uninstalled tools',
            'managementUrl' => '/admin/uninstalled-tools',
            'count' => 9,
        ],
    ], installed: false);
    registerPublishingWorkflowManifest('vendor/route-tools', [
        [
            'type' => 'workflow-attention',
            'label' => 'Route tools',
            'managementRoute' => 'workflow.route',
            'count' => 2,
        ],
    ]);

    expect(Route::has('workflow.route'))->toBeTrue()
        ->and(CapellCore::isPackageInstalled('vendor/route-tools'))->toBeTrue();

    $entry = BuildPublishingWorkflowEntryAction::run(test()->authenticatedUser());

    expect($entry)->not->toBeNull()
        ->and($entry->label)->toBe('Route tools')
        ->and($entry->url)->toBe(route('workflow.route'))
        ->and($entry->count)->toBe(2);
});

/**
 * @param  list<array<string, mixed>>  $contributes
 */
function registerPublishingWorkflowManifest(string $name, array $contributes, bool $installed = true): void
{
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: $name,
        overrides: [
            'contributes' => $contributes,
        ],
    ));

    resolve(CapellPackageRegistry::class)->fill([
        ...resolve(CapellPackageRegistry::class)->all(),
        $manifest->name => $manifest,
    ]);
    CapellCore::registerManifestPackage($manifest);

    if ($installed) {
        CapellCore::forcePackageInstalled($manifest->name);
    }
}
