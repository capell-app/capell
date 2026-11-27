<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Filament\Pages\MarketplacePage;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('places the account status above the catalogue instead of in a sidebar beside it', function (): void {
    Permission::findOrCreate('View:ExtensionsPage', 'web');
    Permission::findOrCreate(ExtensionsPage::MANAGE_PERMISSION, 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(['View:ExtensionsPage', ExtensionsPage::MANAGE_PERMISSION]);

    $html = Livewire::test(MarketplacePage::class)->assertSuccessful()->html();

    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);

    $status = $xpath->query('//*[@id="capell-marketplace-status-title"]');
    $statusInsideAside = $xpath->query('//aside//*[@id="capell-marketplace-status-title"]');

    expect($status instanceof DOMNodeList ? $status->length : null)->toBe(1)
        ->and($statusInsideAside instanceof DOMNodeList ? $statusInsideAside->length : null)->toBe(0)
        ->and(mb_strpos($html, 'capell-marketplace-status-title'))
        ->toBeLessThan(mb_strpos($html, 'capell-marketplace-browser-modal-footer'));
});
