<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\PageUrls\Pages\ManagePageUrls;
use Capell\Admin\Policies\RedirectPolicy;
use Capell\Admin\Tests\Fixtures\Models\RealSiteScopedAdminResourceUser;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses()->group('page-url');

afterEach(function (): void {
    auth()->logout();
});

/** @param list<string> $permissionNames */
function pageUrlOwnershipActor(Site $site, string $roleName, array $permissionNames): RealSiteScopedAdminResourceUser
{
    $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

    $user = new RealSiteScopedAdminResourceUser;
    $user->forceFill([
        'name' => $roleName,
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->save();

    foreach ($permissionNames as $permissionName) {
        $user->givePermissionTo(Permission::findOrCreate($permissionName, 'web'));
    }

    DB::table('model_has_roles')->insert([
        'role_id' => $role->getKey(),
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'team_id' => $site->getKey(),
    ]);
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->refresh();
}

/** @return list<string> */
function pageUrlOwnershipRedirectPermissions(): array
{
    return [
        'ViewAny:PageUrl', 'View:PageUrl', 'Create:PageUrl', 'Update:PageUrl', 'Delete:PageUrl',
        'DeleteAny:PageUrl', 'Restore:PageUrl', 'RestoreAny:PageUrl', 'ForceDelete:PageUrl',
        'ForceDeleteAny:PageUrl', 'Replicate:PageUrl',
    ];
}

/** @return array{site: Site, canonical: PageUrl, alias: PageUrl, redirect: PageUrl} */
function pageUrlOwnershipRecords(): array
{
    $language = Language::factory()->createOne();
    $page = Page::factory()->recycle($language)->withTranslations()->createOne();

    $canonical = PageUrl::query()
        ->where('pageable_type', $page->getMorphClass())
        ->where('pageable_id', $page->getKey())
        ->firstOrFail();

    $redirect = PageUrl::query()->create([
        'site_id' => $canonical->site_id,
        'language_id' => $canonical->language_id,
        'url' => '/old-' . fake()->unique()->slug(),
        'target_url' => '/new',
        'type' => UrlTypeEnum::Redirect,
        'is_manual' => true,
        'status' => true,
    ]);

    $alias = PageUrl::query()->create([
        'site_id' => $canonical->site_id,
        'language_id' => $canonical->language_id,
        'pageable_type' => $page->getMorphClass(),
        'pageable_id' => $page->getKey(),
        'url' => '/alias-' . fake()->unique()->slug(),
        'type' => UrlTypeEnum::Alias,
        'status' => true,
    ]);

    return ['site' => $canonical->site, 'canonical' => $canonical, 'alias' => $alias, 'redirect' => $redirect];
}

it('stops a redirects-only role changing, deleting or restoring a canonical page url', function (): void {
    ['site' => $site, 'canonical' => $canonical] = pageUrlOwnershipRecords();
    $user = pageUrlOwnershipActor($site, 'redirect-editor', pageUrlOwnershipRedirectPermissions());
    $policy = new RedirectPolicy;

    expect($policy->update($user, $canonical))->toBeFalse()
        ->and($policy->delete($user, $canonical))->toBeFalse()
        ->and($policy->restore($user, $canonical))->toBeFalse()
        ->and($policy->forceDelete($user, $canonical))->toBeFalse()
        ->and($policy->replicate($user, $canonical))->toBeFalse()
        ->and($policy->view($user, $canonical))->toBeFalse();
});

it('keeps redirect editing for a redirects-only role', function (): void {
    ['site' => $site, 'redirect' => $redirect] = pageUrlOwnershipRecords();
    $user = pageUrlOwnershipActor($site, 'redirect-editor', pageUrlOwnershipRedirectPermissions());
    $policy = new RedirectPolicy;

    expect($policy->view($user, $redirect))->toBeTrue()
        ->and($policy->update($user, $redirect))->toBeTrue()
        ->and($policy->delete($user, $redirect))->toBeTrue()
        ->and($policy->restore($user, $redirect))->toBeTrue()
        ->and($policy->forceDelete($user, $redirect))->toBeTrue();
});

it('lets a page editor manage the canonical urls of pages they can edit, but not redirects', function (): void {
    ['site' => $site, 'canonical' => $canonical, 'redirect' => $redirect] = pageUrlOwnershipRecords();
    $user = pageUrlOwnershipActor($site, 'page-editor', ['ViewAny:Page', 'View:Page', 'Update:Page']);
    $policy = new RedirectPolicy;

    expect($policy->view($user, $canonical))->toBeTrue()
        ->and($policy->update($user, $canonical))->toBeTrue()
        ->and($policy->delete($user, $canonical))->toBeTrue()
        ->and($policy->restore($user, $canonical))->toBeTrue()
        ->and($policy->replicate($user, $canonical))->toBeFalse()
        ->and($policy->update($user, $redirect))->toBeFalse()
        ->and($policy->delete($user, $redirect))->toBeFalse();
});

it('refuses a redirects-only role on the urls of a deleted page', function (): void {
    ['site' => $site, 'canonical' => $canonical] = pageUrlOwnershipRecords();
    $user = pageUrlOwnershipActor($site, 'redirect-editor', pageUrlOwnershipRedirectPermissions());
    $canonical->pageable?->delete();
    $url = PageUrl::withTrashed()->whereKey($canonical->getKey())->firstOrFail();
    $policy = new RedirectPolicy;

    expect($url->pageable)->toBeNull()
        ->and($policy->update($user, $url))->toBeFalse()
        ->and($policy->delete($user, $url))->toBeFalse()
        ->and($policy->restore($user, $url))->toBeFalse();
});

it('refuses bulk delete, restore and force delete of canonical urls for a redirects-only role', function (): void {
    ['site' => $site, 'alias' => $canonical] = pageUrlOwnershipRecords();
    $user = pageUrlOwnershipActor($site, 'redirect-editor', pageUrlOwnershipRedirectPermissions());
    actingAs($user);

    Livewire::test(ManagePageUrls::class)
        ->assertCanSeeTableRecords([$canonical])
        ->selectTableRecords([$canonical->getKey()])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

    expect(PageUrl::withTrashed()->whereKey($canonical->getKey())->firstOrFail()->trashed())->toBeFalse();

    $canonical->delete();

    Livewire::test(ManagePageUrls::class)
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$canonical])
        ->selectTableRecords([$canonical->getKey()])
        ->callAction(TestAction::make(RestoreBulkAction::class)->table()->bulk());

    expect(PageUrl::withTrashed()->whereKey($canonical->getKey())->firstOrFail()->trashed())->toBeTrue();

    Livewire::test(ManagePageUrls::class)
        ->filterTable('trashed', true)
        ->selectTableRecords([$canonical->getKey()])
        ->callAction(TestAction::make(ForceDeleteBulkAction::class)->table()->bulk());

    expect(PageUrl::withTrashed()->whereKey($canonical->getKey())->exists())->toBeTrue();
});

it('still lets a role with page and redirect permissions bulk delete canonical urls', function (): void {
    ['site' => $site, 'alias' => $canonical] = pageUrlOwnershipRecords();
    $user = pageUrlOwnershipActor($site, 'url-manager', [...pageUrlOwnershipRedirectPermissions(), 'ViewAny:Page', 'View:Page', 'Update:Page']);
    actingAs($user);

    Livewire::test(ManagePageUrls::class)
        ->assertCanSeeTableRecords([$canonical])
        ->assertActionVisible(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->selectTableRecords([$canonical->getKey()])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

    expect(PageUrl::withTrashed()->whereKey($canonical->getKey())->firstOrFail()->trashed())->toBeTrue();
});
