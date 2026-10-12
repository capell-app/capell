<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\PageHeaderActionExtender;
use Capell\Admin\Contracts\Extenders\PageSchemaExtender;
use Capell\Admin\Contracts\Extenders\SiteSchemaExtender;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\Sites\Pages\EditSite;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Tests\Fixtures\Filament\PackagePageRelationManager;
use Capell\Admin\Tests\Fixtures\Filament\PackageSiteRelationManager;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

it('renders and authorises contributed page header actions on the edit consumer', function (bool $allowed): void {
    $page = Page::factory()->createOne();
    foreach (['View:Page', 'Update:Page', 'Use:PackagePageAction'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = test()->createUserWithPermission(['View:Page', 'Update:Page']);
    $user->assignedSiteIds = collect([$page->site_id]);
    if ($allowed) {
        $user->givePermissionTo('Use:PackagePageAction');
    }

    test()->actingAs($user);

    app()->instance('test-page-header-extender', new class implements PageHeaderActionExtender
    {
        #[Override]
        public function actions(): array
        {
            return [Action::make('package-page-action')
                ->label('Package page action')
                ->visible(fn (): bool => auth()->user()?->can('Use:PackagePageAction') === true)
                ->action(fn (EditPage $livewire) => $livewire->dispatch('package-page-action-ran'))];
        }
    });
    app()->tag(['test-page-header-extender'], PageHeaderActionExtender::TAG);

    $livewire = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])->assertSuccessful();

    if ($allowed) {
        $livewire->assertActionVisible('package-page-action')
            ->assertSee('Package page action')
            ->callAction('package-page-action')
            ->assertDispatched('package-page-action-ran');
    } else {
        $livewire->assertActionHidden('package-page-action')
            ->assertDontSee('Package page action')
            ->call('mountAction', 'package-page-action')
            ->call('callMountedAction')
            ->assertNotDispatched('package-page-action-ran');
    }
})->with([true, false]);

it('filters contributed site relation managers through the edit consumer', function (bool $allowed): void {
    $site = Site::factory()->createOne();
    foreach (['ViewAny:Site', 'Update:Site', 'View:PackageNavigation'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = test()->createUserWithPermission(['ViewAny:Site', 'Update:Site']);
    $user->assignedSiteIds = collect([$site->id]);
    if ($allowed) {
        $user->givePermissionTo('View:PackageNavigation');
    }

    test()->actingAs($user);

    $extender = Mockery::mock(SiteSchemaExtender::class);
    $extender->shouldReceive('extendRelationManagers')
        ->withArgs(fn (Model $record, array $managers): bool => $record->is($site) && $managers === SiteResource::getRelations())
        ->andReturnUsing(fn (Model $record, array $managers): array => [...$managers, PackageSiteRelationManager::class]);
    $extender->shouldReceive('extendTranslationComponentsForHook', 'extendCreateWizardComponentsForHook')->andReturn([]);
    $extender->shouldReceive('extendTabs', 'extendSiteMetaDetailsComponents')
        ->andReturnUsing(fn (Schema $schema, array $components): array => $components);
    app()->instance('test-site-schema-extender', $extender);
    app()->tag(['test-site-schema-extender'], SiteSchemaExtender::TAG);

    $livewire = Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])->assertSuccessful();
    expect(in_array(PackageSiteRelationManager::class, $livewire->instance()->getRelationManagers(), true))->toBe($allowed);

    if ($allowed) {
        $livewire->assertSee('Package navigations');
    } else {
        $livewire->assertDontSee('Package navigations');
    }
})->with([true, false]);

it('consumes and authorises page schema relation contributions on the edit page', function (bool $allowed): void {
    $page = Page::factory()->createOne();
    foreach (['View:Page', 'Update:Page', 'View:PackagePageRelations'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = test()->createUserWithPermission(['View:Page', 'Update:Page']);
    $user->assignedSiteIds = collect([$page->site_id]);
    if ($allowed) {
        $user->givePermissionTo('View:PackagePageRelations');
    }

    test()->actingAs($user);

    $extender = Mockery::mock(PageSchemaExtender::class);
    $extender->shouldReceive('extendRelationManagers')
        ->withArgs(fn (Model $record, array $managers): bool => $record->is($page))
        ->andReturnUsing(fn (Model $record, array $managers): array => [...$managers, PackagePageRelationManager::class]);
    $extender->shouldReceive('extendTabs')
        ->andReturnUsing(fn (Schema $schema, array $tabs): array => $tabs);
    $extender->shouldReceive('extendTranslationComponentsForHook', 'extendSidebarComponents')->andReturn([]);
    app()->instance('test-page-schema-extender', $extender);
    app()->tag(['test-page-schema-extender'], PageSchemaExtender::TAG);

    $livewire = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])->assertSuccessful();
    expect(in_array(PackagePageRelationManager::class, $livewire->instance()->getRelationManagers(), true))->toBe($allowed);

    if ($allowed) {
        $livewire->assertSee('Package page relations');
    } else {
        $livewire->assertDontSee('Package page relations');
    }
})->with([true, false]);

it('preserves the built in site relations when there are no contributions', function (): void {
    test()->actingAsAdmin();
    $site = Site::factory()->createOne();
    $livewire = Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])->assertSuccessful();

    expect($livewire->instance()->getRelationManagers())->toBe(SiteResource::getRelations());
});
