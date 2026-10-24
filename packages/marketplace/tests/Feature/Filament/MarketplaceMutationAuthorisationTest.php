<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Enums\MarketplacePermission;
use Capell\Marketplace\Filament\Livewire\MarketplaceExtensionsBrowser;
use Capell\Marketplace\Filament\Pages\MarketplaceExtensionDetailPage;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueTable;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    $actor = User::factory()->create();
    foreach (MarketplacePermission::names() as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    test()->actingAs($actor);
    Http::fake([]);
    Queue::fake();
});

it('refuses every update entry point to a view-only user before any remote or queue operation', function (string $entry): void {
    expect(MarketplaceExtensionDetailPage::canAccess())->toBeTrue()
        ->and(ExtensionsPage::canManageExtensions())->toBeFalse();
    try {
        match ($entry) {
            'detail' => (new MarketplaceExtensionDetailPage)->updateExtension(),
            'card' => (new MarketplaceExtensionsBrowser)->updateMarketplaceRecordFromCard('vendor/package'),
            'bulk' => (new MarketplaceExtensionsBrowser)->updateSelectedMarketplaceRecords(),
            'table' => resolve(MarketplaceCatalogueTable::class)->updateExtension(['composer_name' => 'vendor/package']),
            default => throw new InvalidArgumentException('Unknown update entry point.'),
        };
        test()->fail('View access must not authorise a package update.');
    } catch (HttpException $httpException) {
        expect($httpException->getStatusCode())->toBe(403);
    }

    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with(['detail', 'card', 'bulk', 'table']);

it('refuses licence activation to a view-only user before validating or acquiring an installation', function (): void {
    $page = new MarketplaceExtensionDetailPage;
    $page->extensionSlug = 'private-package';
    $page->licenseKey = 'licence-value';
    try {
        $page->activateLicence();
        test()->fail('View access must not authorise a Composer installation.');
    } catch (HttpException $httpException) {
        expect($httpException->getStatusCode())->toBe(403);
    }

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('refuses every install entry point to a view-only user before any remote or queue operation', function (string $entry): void {
    expect(MarketplaceExtensionDetailPage::canAccess())->toBeTrue()
        ->and(ExtensionsPage::canManageExtensions())->toBeFalse();

    try {
        match ($entry) {
            'card' => (new MarketplaceExtensionsBrowser)->installMarketplaceRecordFromCard('vendor/package'),
            'review' => (new MarketplaceExtensionsBrowser)->installReviewedMarketplaceExtensions(),
            'table' => resolve(MarketplaceCatalogueTable::class)->installExtension(['slug' => 'package']),
            default => throw new InvalidArgumentException('Unknown install entry point.'),
        };
        test()->fail('View access must not authorise a package installation.');
    } catch (HttpException $httpException) {
        expect($httpException->getStatusCode())->toBe(403);
    }

    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with(['card', 'review', 'table']);

it('withdraws licence and update offers when management permission is revoked', function (): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);
    CapellCore::registerPackage('vendor/package', version: '1.0.0');
    CapellCore::forcePackageInstalled('vendor/package');
    Http::fake([
        'https://marketplace.test/api/extensions/package' => Http::response([
            'data' => [
                'slug' => 'package',
                'composer_name' => 'vendor/package',
                'latest_version' => '2.0.0',
                'install_eligibility' => ['state' => 'activation_required'],
            ],
        ]),
        'https://marketplace.test/api/extensions/update-package' => Http::response([
            'data' => [
                'slug' => 'update-package',
                'composer_name' => 'vendor/package',
                'latest_version' => '2.0.0',
                'install_eligibility' => 'allowed',
            ],
        ]),
    ]);
    $actor = test()->authenticatedUser();
    $permission = Permission::findOrCreate(ExtensionsPage::MANAGE_PERMISSION, 'web');
    $actor->givePermissionTo($permission);
    $page = new MarketplaceExtensionDetailPage;
    $page->extensionSlug = 'package';

    $updatePage = new MarketplaceExtensionDetailPage;
    $updatePage->extensionSlug = 'update-package';

    $browser = new MarketplaceExtensionsBrowser;
    $record = ['composer_name' => 'vendor/package', 'is_installed' => true, 'has_update_available' => true];

    expect($page->requiresLicenceKey())->toBeTrue()
        ->and($updatePage->canUpdate())->toBeTrue()
        ->and($browser->marketplaceRecordCanUpdate($record))->toBeTrue();

    $actor->revokePermissionTo($permission);

    expect(MarketplaceExtensionDetailPage::canAccess())->toBeTrue()
        ->and($page->requiresLicenceKey())->toBeFalse()
        ->and($updatePage->canUpdate())->toBeFalse()
        ->and($browser->marketplaceRecordCanUpdate($record))->toBeFalse();

    Queue::assertNothingPushed();
});
