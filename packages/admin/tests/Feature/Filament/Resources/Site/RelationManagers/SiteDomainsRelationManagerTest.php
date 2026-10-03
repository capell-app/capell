<?php

declare(strict_types=1);

use Capell\Admin\Actions\Shield\ResolveDefaultRolePermissionsAction;
use Capell\Admin\Filament\Resources\Sites\Pages\EditSite;
use Capell\Admin\Filament\Resources\Sites\RelationManagers\SiteDomainsRelationManager;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Tests\Fixtures\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\assertSoftDeleted;

use Spatie\Permission\Models\Permission;

it('can list domains', function (): void {
    test()->actingAsAdmin();

    $site = Site::factory()
        ->has(SiteDomain::factory()->count(10))
        ->create();

    $siteDomain = $site->siteDomains->first();

    $component = Livewire::test(SiteDomainsRelationManager::class, [
        'ownerRecord' => $site,
        'pageClass' => EditSite::class,
    ])
        ->assertSuccessful()
        ->assertCountTableRecords(10)
        ->assertCanSeeTableRecords($site->siteDomains)
        ->assertTableColumnStateSet('full_url', [$siteDomain->full_url], record: $siteDomain);

    expect($component->instance()->getTable()->getRecordTitle($siteDomain))
        ->toBe($siteDomain->full_url);
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

it('can search a full URL when the stored scheme is null', function (): void {
    test()->actingAsAdmin();
    config()->set('capell-frontend.default_scheme', 'https');

    $site = Site::factory()->createOne();
    $siteDomain = SiteDomain::factory()
        ->site($site)
        ->createOne([
            'scheme' => null,
            'domain' => 'scheme-null.test',
            'path' => '/docs',
        ]);

    Livewire::test(SiteDomainsRelationManager::class, [
        'ownerRecord' => $site,
        'pageClass' => EditSite::class,
    ])
        ->assertSuccessful()
        ->searchTable($siteDomain->full_url)
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

it('preserves domain creation editing and deletion for a site admin with default permissions', function (): void {
    $site = Site::factory()->createOne();
    $foreign = Site::factory()->createOne();
    $actor = User::factory()->createOne();
    $actor->assignedSiteIds = collect([$site->id]);
    foreach (['ViewAny:Site', 'View:Site', 'Update:Site', 'UpdateOwn:Site', 'Create:Site', 'Delete:Site', 'DeleteAny:Site'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $actor->givePermissionTo(ResolveDefaultRolePermissionsAction::run('admin', 'web'));
    test()->actingAs($actor);
    expect($actor->checkPermissionTo('Create:Site'))->toBeFalse()
        ->and($actor->checkPermissionTo('Delete:Site'))->toBeFalse();

    $component = Livewire::test(SiteDomainsRelationManager::class, [
        'ownerRecord' => $site, 'pageClass' => EditSite::class,
    ])->callAction(TestAction::make(CreateAction::class)->table(), data: [
        'scheme' => 'https', 'domain' => 'own-site.test', 'path' => '',
        'language_id' => $site->language_id, 'default' => false, 'status' => true,
    ])->assertHasNoFormErrors();
    $domain = $site->siteDomains()->where('domain', 'own-site.test')->firstOrFail();
    $component->callAction(TestAction::make(EditAction::class)->table($domain), data: [
        'scheme' => 'https', 'domain' => 'edited-own-site.test', 'path' => '',
        'language_id' => $site->language_id, 'default' => false, 'status' => true,
    ])->assertHasNoFormErrors();
    expect($domain->fresh()->domain)->toBe('edited-own-site.test');
    $component->callAction(TestAction::make(DeleteAction::class)->table($domain));
    assertSoftDeleted($domain);

    $bulkDomain = SiteDomain::factory()->site($site)->createOne();
    Livewire::test(SiteDomainsRelationManager::class, [
        'ownerRecord' => $site, 'pageClass' => EditSite::class,
    ])->selectTableRecords([$bulkDomain])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());
    assertSoftDeleted($bulkDomain);

    Livewire::test(SiteDomainsRelationManager::class, [
        'ownerRecord' => $foreign, 'pageClass' => EditSite::class,
    ])->assertActionHidden(TestAction::make(CreateAction::class)->table())
        ->assertActionHidden(TestAction::make(DeleteBulkAction::class)->table()->bulk());
    expect($foreign->siteDomains()->count())->toBe(0);
});
