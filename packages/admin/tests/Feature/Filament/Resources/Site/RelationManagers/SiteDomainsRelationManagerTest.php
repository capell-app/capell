<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Sites\Pages\EditSite;
use Capell\Admin\Filament\Resources\Sites\RelationManagers\SiteDomainsRelationManager;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\assertSoftDeleted;

it('can list domains', function (): void {
    test()->actingAsAdmin();

    $site = Site::factory()
        ->has(SiteDomain::factory()->count(10))
        ->create();

    $siteDomain = $site->siteDomains->first();

    Livewire::test(SiteDomainsRelationManager::class, [
        'ownerRecord' => $site,
        'pageClass' => EditSite::class,
    ])
        ->assertSuccessful()
        ->assertCountTableRecords(10)
        ->assertCanSeeTableRecords($site->siteDomains)
        ->assertTableColumnStateSet('full_url', [$siteDomain->full_url], record: $siteDomain);
});

it('shows domain guidance when the site has no domains', function (): void {
    test()->actingAsAdmin();

    $site = Site::factory()->createOne();

    Livewire::test(SiteDomainsRelationManager::class, [
        'ownerRecord' => $site,
        'pageClass' => EditSite::class,
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::generic.no_site_domains'))
        ->assertSee(__('capell-admin::generic.no_site_domains_description'));
});

it('can search domains', function (): void {
    test()->actingAsAdmin();

    $site = Site::factory()
        ->has(SiteDomain::factory()->count(10))
        ->create();

    $siteDomain = $site->siteDomains->random();

    Livewire::test(SiteDomainsRelationManager::class, [
        'ownerRecord' => $site,
        'pageClass' => EditSite::class,
    ])
        ->assertSuccessful()
        ->searchTable($siteDomain->getKey())
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$siteDomain]);
});

it('can bulk delete domains', function (): void {
    test()->actingAsAdmin();

    $site = Site::factory()->createOne();

    $siteDomains = SiteDomain::factory(['site_id' => $site->id])->count(10)->create();

    Livewire::test(SiteDomainsRelationManager::class, [
        'ownerRecord' => $site,
        'pageClass' => EditSite::class,
    ])
        ->assertSuccessful()
        ->selectTableRecords($siteDomains)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertHasNoFormErrors();

    foreach ($siteDomains as $siteDomain) {
        assertSoftDeleted($siteDomain, ['id' => $siteDomain->id]);
    }
});

it('can update a domain', function (): void {
    test()->actingAsAdmin();

    $site = Site::factory()
        ->has(SiteDomain::factory()->count(2))
        ->create();

    $siteDomain = $site->siteDomains->first();

    Livewire::test(SiteDomainsRelationManager::class, [
        'ownerRecord' => $site,
        'pageClass' => EditSite::class,
    ])
        ->assertSuccessful()
        ->callAction(
            TestAction::make(EditAction::class)->table($siteDomain),
            data: [
                'scheme' => 'https',
                'domain' => 'example.com',
                'path' => '/docs',
                'language_id' => $siteDomain->language_id,
                'default' => true,
                'status' => '0',
            ],
        )
        ->assertHasNoFormErrors();

    expect($siteDomain->refresh())
        ->scheme->toBe('https')
        ->domain->toBe('example.com')
        ->path->toBe('/docs')
        ->default->toBeTrue()
        ->status->toBeFalse();

});
