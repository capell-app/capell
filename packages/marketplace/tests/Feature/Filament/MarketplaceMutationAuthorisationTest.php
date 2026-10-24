<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Enums\MarketplacePermission;
use Capell\Marketplace\Filament\Livewire\MarketplaceExtensionsBrowser;
use Capell\Marketplace\Filament\Pages\MarketplaceExtensionDetailPage;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueTable;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $actor = User::factory()->create();
    foreach (MarketplacePermission::names() as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    test()->actingAs($actor);
    Http::fake();
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
