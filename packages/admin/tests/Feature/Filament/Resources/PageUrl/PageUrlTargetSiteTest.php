<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\PageUrls\Pages\ManagePageUrls;
use Capell\Admin\Policies\RedirectPolicy;
use Capell\Admin\Tests\Fixtures\Models\RealSiteScopedAdminResourceUser;
use Capell\Admin\Tests\Support\ScopedAdminUser;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses()->group('page-url');

/**
 * @return array{siteA: Site, siteB: Site, language: Language, pageA: Page, otherPageA: Page, pageB: Page, url: PageUrl}
 */
function pageUrlTargetFixtures(): array
{
    $language = Language::factory()->createOne();
    $siteA = Site::factory()->recycle($language)->withTranslations()->createOne();
    $siteB = Site::factory()->recycle($language)->withTranslations()->createOne();
    $pageA = Page::factory()->recycle($siteA)->recycle($language)->withTranslations()->createOne(['name' => 'Site A landing']);
    $otherPageA = Page::factory()->recycle($siteA)->recycle($language)->withTranslations()->createOne(['name' => 'Site A pricing']);
    $pageB = Page::factory()->recycle($siteB)->recycle($language)->withTranslations()->createOne(['name' => 'Site B confidential launch']);

    return [
        'siteA' => $siteA,
        'siteB' => $siteB,
        'language' => $language,
        'pageA' => $pageA,
        'otherPageA' => $otherPageA,
        'pageB' => $pageB,
        'url' => $pageA->pageUrl,
    ];
}

it('refuses a forged edit that points a site URL at a page of another site and leaves the row unchanged', function (): void {
    ['siteA' => $siteA, 'language' => $language, 'pageA' => $pageA, 'pageB' => $pageB, 'url' => $url] = pageUrlTargetFixtures();
    actingAs(ScopedAdminUser::make(collect([$siteA->getKey()])));

    Livewire::test(ManagePageUrls::class)
        ->callAction(TestAction::make(EditAction::class)->table($url), data: [
            'site_id' => $siteA->getKey(),
            'language_id' => $language->getKey(),
            'pageable_type' => $pageB->getMorphClass(),
            'pageable_id' => $pageB->getKey(),
            'url' => $url->url,
            'status' => '1',
        ])
        ->assertHasFormErrors(['pageable_id']);

    expect($url->refresh())
        ->pageable_id->toBe($pageA->getKey())
        ->site_id->toBe($siteA->getKey());
});

it('still lets the editor point a site URL at another page of the same site', function (): void {
    ['siteA' => $siteA, 'language' => $language, 'otherPageA' => $otherPageA, 'url' => $url] = pageUrlTargetFixtures();
    actingAs(ScopedAdminUser::make(collect([$siteA->getKey()])));

    Livewire::test(ManagePageUrls::class)
        ->callAction(TestAction::make(EditAction::class)->table($url), data: [
            'site_id' => $siteA->getKey(),
            'language_id' => $language->getKey(),
            'pageable_type' => $otherPageA->getMorphClass(),
            'pageable_id' => $otherPageA->getKey(),
            'url' => $url->url,
            'status' => '1',
        ])
        ->assertHasNoFormErrors();

    expect($url->refresh()->pageable_id)->toBe($otherPageA->getKey());
});

it('refuses a forged create that points a new site URL at a page of another site', function (): void {
    ['siteA' => $siteA, 'language' => $language, 'pageB' => $pageB] = pageUrlTargetFixtures();
    actingAs(ScopedAdminUser::make(collect([$siteA->getKey()])));
    $before = PageUrl::query()->count();

    Livewire::test(ManagePageUrls::class)
        ->callAction(CreateAction::class, data: [
            'site_id' => $siteA->getKey(),
            'language_id' => $language->getKey(),
            'pageable_type' => $pageB->getMorphClass(),
            'pageable_id' => $pageB->getKey(),
            'url' => '/forged-site-b-page',
            'status' => '1',
        ])
        ->assertHasFormErrors(['pageable_id']);

    expect(PageUrl::query()->count())->toBe($before)
        ->and(PageUrl::query()->where('url', '/forged-site-b-page')->exists())->toBeFalse();
});

it('lists no page of another site in the page picker', function (): void {
    ['siteA' => $siteA, 'language' => $language, 'pageA' => $pageA, 'otherPageA' => $otherPageA, 'pageB' => $pageB] = pageUrlTargetFixtures();
    actingAs(ScopedAdminUser::make(collect([$siteA->getKey()])));
    $checked = false;

    Livewire::test(ManagePageUrls::class)
        ->mountAction(CreateAction::class)
        ->fillForm([
            'site_id' => $siteA->getKey(),
            'language_id' => $language->getKey(),
            'pageable_type' => $pageA->getMorphClass(),
        ])
        ->assertFormFieldExists('pageable_id', 'mountedActionSchema0', function (Select $select) use ($otherPageA, $pageB, &$checked): bool {
            $checked = true;

            expect($select->getOptions())->toHaveKey($otherPageA->getKey())
                ->not->toHaveKey($pageB->getKey())
                ->and($select->getSearchResults('Site B'))->toBe([]);

            return true;
        });

    expect($checked)->toBeTrue();
});

it('asks the policy about the new target as well as the current owner', function (): void {
    ['siteA' => $siteA, 'otherPageA' => $otherPageA, 'pageB' => $pageB, 'url' => $url] = pageUrlTargetFixtures();
    $actor = ScopedAdminUser::make(collect([$siteA->getKey()]));
    $policy = new RedirectPolicy;

    expect($policy->update($actor, $url, $otherPageA))->toBeTrue()
        ->and($policy->update($actor, $url, $pageB))->toBeFalse()
        ->and($policy->create($actor, $otherPageA))->toBeTrue()
        ->and($policy->create($actor, $pageB))->toBeFalse();
});

it('refuses a real page URL editor a forged re-point to another site and keeps the row', function (): void {
    ['siteA' => $siteA, 'language' => $language, 'pageA' => $pageA, 'pageB' => $pageB, 'url' => $url] = pageUrlTargetFixtures();
    $role = Role::query()->firstOrCreate(['name' => 'url-editor-a', 'guard_name' => 'web']);
    $user = new RealSiteScopedAdminResourceUser;
    $user->forceFill(['name' => 'URL editor', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')])->save();

    foreach (['ViewAny:PageUrl', 'View:PageUrl', 'Update:PageUrl', 'Create:PageUrl', 'ViewAny:Page', 'View:Page', 'Update:Page'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    DB::table('model_has_roles')->insert([
        'role_id' => $role->getKey(),
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'team_id' => $siteA->getKey(),
    ]);
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    actingAs($user->refresh());

    expect(Gate::forUser($user)->allows('update', [$url, $pageB]))->toBeFalse();

    Livewire::test(ManagePageUrls::class)
        ->callAction(TestAction::make(EditAction::class)->table($url), data: [
            'site_id' => $siteA->getKey(),
            'language_id' => $language->getKey(),
            'pageable_type' => $pageB->getMorphClass(),
            'pageable_id' => $pageB->getKey(),
            'url' => $url->url,
            'status' => '1',
        ])
        ->assertHasFormErrors(['pageable_id']);

    expect($url->refresh()->pageable_id)->toBe($pageA->getKey());
});
