<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\PageUrls\Pages\ManagePageUrls;
use Capell\Admin\Filament\Resources\PageUrls\PageUrlResource;
use Capell\Admin\Policies\RedirectPolicy;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\get;

use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)
    ->group('page', 'page-url');

it('admin can see page urls', function (): void {
    test()->actingAsAdmin();

    get(PageUrlResource::getUrl())
        ->assertOk();
});

it('admin render page urls page with redirect filter', function (): void {
    test()->actingAsAdmin();

    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->create();
    SiteDomain::factory()->default()->recycle($site)->recycle($language)->create();
    $page = Page::factory()->recycle($site)->create();
    $redirect = PageUrl::factory()->site($site)->language($language)->page($page)->redirect()->create();
    Translation::factory()->language($language)->translatable($page)->create();
    $canonical = PageUrl::query()->where('pageable_id', $page->getKey())->whereNull('type')->firstOrFail();

    Livewire::test(ManagePageUrls::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$redirect, $canonical])
        ->filterTable('type', 'redirect')
        ->assertCanSeeTableRecords([$redirect])
        ->assertCanNotSeeTableRecords([$canonical]);
});

it('filters page URLs by both pageable type and identifier', function (): void {
    test()->actingAsAdmin();

    $firstPage = Page::factory()->createOne(['name' => 'First pageable page']);
    $secondPage = Page::factory()->site($firstPage->site)->createOne(['name' => 'Second pageable page']);
    $firstUrl = PageUrl::factory()->page($firstPage)->site($firstPage->site)->language($firstPage->site->language)->createOne(['url' => '/first-pageable']);
    $secondUrl = PageUrl::factory()->page($secondPage)->site($secondPage->site)->language($secondPage->site->language)->createOne(['url' => '/second-pageable']);

    Livewire::test(ManagePageUrls::class)
        ->assertSuccessful()
        ->set('tableFilters.pageable', [
            'pageable_type' => $firstPage->getMorphClass(),
            'pageable_id' => $firstPage->getKey(),
        ])
        ->assertCanSeeTableRecords([$firstUrl])
        ->assertCanNotSeeTableRecords([$secondUrl]);
});

it('cannot see page urls', function (): void {
    test()->actingAsUser();

    get(PageUrlResource::getUrl())
        ->assertForbidden();
});

it('checks site access for every listed page URL without a site query per row', function (): void {
    test()->actingAsAdmin();
    $admin = auth()->user();
    throw_unless($admin instanceof User, LogicException::class);
    $permission = Closure::bind(static fn (): string => RedirectPolicy::permission('view_any', 'PageUrl'), null, RedirectPolicy::class)();
    $admin->givePermissionTo(Permission::findOrCreate($permission, 'web'));

    $language = Language::factory()->createOne();

    foreach (range(1, 4) as $number) {
        $site = Site::factory()->recycle($language)->createOne();
        PageUrl::factory()->site($site)->language($language)->createOne(['url' => '/listed-' . $number, 'pageable_id' => null, 'pageable_type' => null, 'is_manual' => true, 'type' => 'redirect']);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $records = PageUrlResource::getEloquentQuery()->get();
    foreach ($records as $record) {
        throw_unless($record instanceof PageUrl, LogicException::class);
        resolve(RedirectPolicy::class)->view($admin, $record);
    }

    $siteQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "sites"') || str_contains($query['query'], 'from `sites`'));

    expect($records)->toHaveCount(4)
        ->and($siteQueries)->toHaveCount(1);
});
