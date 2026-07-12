<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Redirects\Pages\ManageRedirects;
use Capell\Admin\Policies\RedirectPolicy;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)
    ->group('redirect');

beforeEach(function (): void {
    Gate::policy(PageUrl::class, RedirectPolicy::class);

    foreach (['ViewAny:PageUrl', 'Import:PageUrl', 'Export:PageUrl'] as $permission) {
        Permission::findOrCreate($permission);
    }
});

it('hides redirect import and export actions from users without those permissions', function (): void {
    test()->actingAs(test()->createUserWithPermission('ViewAny:PageUrl'));

    Livewire::test(ManageRedirects::class)
        ->assertSuccessful()
        ->assertActionDoesNotExist('importRedirects')
        ->assertActionHidden(ExportAction::class);
});

it('shows redirect import and export actions to users with the matching permissions', function (): void {
    test()->actingAs(test()->createUserWithPermission([
        'ViewAny:PageUrl',
        'Import:PageUrl',
        'Export:PageUrl',
    ]));

    Livewire::test(ManageRedirects::class)
        ->assertSuccessful()
        ->assertActionVisible('importRedirects')
        ->assertActionVisible(ExportAction::class);
});

it('can create a manual redirect with database-backed fields', function (): void {
    test()->actingAsAdmin();

    $language = Language::factory()->createOne();
    $site = Site::factory()
        ->state(['language_id' => $language->id])
        ->has(SiteDomain::factory()->state(['language_id' => $language->id]))
        ->create();

    Livewire::test(ManageRedirects::class)
        ->assertSuccessful()
        ->mountAction(CreateAction::class)
        ->assertMountedActionModalSee(__('capell-admin::generic.redirect_target_url_info'));

    PageUrl::query()->create([
        'site_id' => $site->id,
        'language_id' => $language->id,
        'url' => '/old-page',
        'target_url' => '/new-page',
        'status_code' => RedirectStatusCodeEnum::Temporary->value,
        'notes' => 'Temporary campaign redirect.',
        'status' => false,
        'type' => UrlTypeEnum::Redirect->value,
        'is_manual' => true,
    ]);

    $redirect = PageUrl::query()->firstWhere('url', '/old-page');

    expect($redirect)
        ->not->toBeNull()
        ->and($redirect)
        ->site_id->toBe($site->id)
        ->language_id->toBe($language->id)
        ->target_url->toBe('/new-page')
        ->status_code->toBe(RedirectStatusCodeEnum::Temporary)
        ->notes->toBe('Temporary campaign redirect.')
        ->status->toBeFalse()
        ->type->toBe(UrlTypeEnum::Redirect)
        ->is_manual->toBeTrue();
});

it('can edit a manual redirect with database-backed fields', function (): void {
    test()->actingAsAdmin();

    $language = Language::factory()->createOne();
    $site = Site::factory()
        ->state(['language_id' => $language->id])
        ->has(SiteDomain::factory()->state(['language_id' => $language->id]))
        ->create();

    $redirect = PageUrl::factory()
        ->site($site)
        ->language($language)
        ->create([
            'url' => '/legacy',
            'target_url' => '/current',
            'status_code' => RedirectStatusCodeEnum::Permanent,
            'notes' => 'Original redirect.',
            'status' => true,
            'type' => UrlTypeEnum::Redirect,
            'is_manual' => true,
        ]);

    Livewire::test(ManageRedirects::class)
        ->assertSuccessful()
        ->callAction(
            TestAction::make(EditAction::class)->table($redirect),
            data: [
                'site_id' => $site->id,
                'language_id' => $language->id,
                'url' => '/legacy-updated',
                'target_url' => '/current-updated',
                'status_code' => RedirectStatusCodeEnum::Temporary->value,
                'notes' => 'Updated redirect.',
                'status' => '0',
                'type' => UrlTypeEnum::Redirect->value,
                'is_manual' => true,
            ],
        )
        ->assertHasNoFormErrors();

    expect($redirect->refresh())
        ->site_id->toBe($site->id)
        ->language_id->toBe($language->id)
        ->url->toBe('/legacy-updated')
        ->target_url->toBe('/current-updated')
        ->status_code->toBe(RedirectStatusCodeEnum::Temporary)
        ->notes->toBe('Updated redirect.')
        ->status->toBeFalse()
        ->type->toBe(UrlTypeEnum::Redirect)
        ->is_manual->toBeTrue();
});

it('mounts the create redirect action with query string defaults', function (): void {
    test()->actingAsAdmin();

    $language = Language::factory()->createOne();
    $site = Site::factory()
        ->state(['language_id' => $language->id])
        ->has(SiteDomain::factory()->state(['language_id' => $language->id]))
        ->create();

    test()->get(ManageRedirects::getUrl([
        'create_redirect' => true,
        'site_id' => $site->id,
        'language_id' => $language->id,
        'url' => '/missing-page',
        'target_url' => '/replacement',
        'status_code' => RedirectStatusCodeEnum::Temporary->value,
    ]))
        ->assertOk();
});

it('ignores blank create redirect query string values', function (): void {
    test()->actingAsAdmin();

    test()->get(ManageRedirects::getUrl([
        'create_redirect' => true,
        'site_id' => 0,
        'language_id' => 0,
        'url' => '',
        'target_url' => '',
    ]))
        ->assertOk();
});
