<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Pages\CreatePage;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\ContentLock;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)
    ->group('page');

beforeEach(function (): void {
    foreach (['ViewAny:Page', 'View:Page', 'Update:Page', 'Create:Page'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
});

afterEach(function (): void {
    Date::setTestNow();
});

it('refuses every page editor update once the editor loses page update permission', function (): void {
    $page = Page::factory()->withTranslations()->createOne(['name' => 'Original Name']);
    $editor = test()->createUserWithPermission(['View:Page', 'Update:Page']);
    $editor->assignedSiteIds = collect([$page->site_id]);

    test()->actingAs($editor);

    $component = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])->assertSuccessful();

    expect(ContentLock::query()->sole()->user_id)->toBe($editor->getKey());

    $editor->revokePermissionTo('Update:Page');

    $component->set('data.name', 'Changed after revocation')->assertForbidden();

    expect($page->refresh()->name)->toBe('Original Name');
});

it('refuses a lock takeover from an editor whose page update permission was revoked after opening the page', function (): void {
    $page = Page::factory()->createOne();
    $owner = test()->createUser(['name' => 'Lock Owner']);
    $editor = test()->createUserWithPermission(['View:Page', 'Update:Page']);
    $editor->assignedSiteIds = collect([$page->site_id]);

    Date::setTestNow('2026-05-31 10:00:00');
    ContentLock::query()->create([
        'user_id' => $owner->getKey(),
        'model_type' => $page->getMorphClass(),
        'model_id' => $page->getKey(),
        'expires_at' => Date::now()->addMinutes(15),
    ]);

    test()->actingAs($editor);
    $component = Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertSuccessful()
        ->assertActionVisible('take-over-content-lock');

    $editor->revokePermissionTo('Update:Page');

    $component->call('mountAction', 'take-over-content-lock')->assertForbidden();

    expect(ContentLock::query()->sole()->user_id)->toBe($owner->getKey());
});

it('keeps updating and saving for an editor who still holds page update permission', function (): void {
    $page = Page::factory()->withTranslations()->createOne(['name' => 'Original Name']);
    $editor = test()->createUserWithPermission(['View:Page', 'Update:Page']);
    $editor->assignedSiteIds = collect([$page->site_id]);

    test()->actingAs($editor);

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertSuccessful()
        ->set('data.name', 'Saved by a permitted editor')
        ->assertSuccessful()
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->refresh()->name)->toBe('Saved by a permitted editor')
        ->and(ContentLock::query()->sole()->user_id)->toBe($editor->getKey());
});

it('keeps a locked-out editor who still holds page update permission in the read-only editor until they take over', function (): void {
    $page = Page::factory()->createOne();
    $owner = test()->createUser(['name' => 'Lock Owner']);
    $editor = test()->createUserWithPermission(['View:Page', 'Update:Page']);
    $editor->assignedSiteIds = collect([$page->site_id]);

    Date::setTestNow('2026-05-31 10:00:00');
    ContentLock::query()->create([
        'user_id' => $owner->getKey(),
        'model_type' => $page->getMorphClass(),
        'model_id' => $page->getKey(),
        'expires_at' => Date::now()->addMinutes(15),
    ]);

    test()->actingAs($editor);

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertSuccessful()
        ->set('data.name', 'Typed while read-only')
        ->assertSuccessful()
        ->assertActionVisible('take-over-content-lock')
        ->callAction('take-over-content-lock')
        ->assertHasNoActionErrors()
        ->assertNotified(__('capell-admin::message.content_lock_taken_over'));

    expect(ContentLock::query()->sole()->user_id)->toBe($editor->getKey());
});

it('refuses every page creation update once the author loses page create permission', function (): void {
    Language::factory()->createOne();
    Site::factory()->withTranslations()->createOne();
    Blueprint::factory()->page()->default()->createOne();
    $author = test()->createUserWithPermission(['ViewAny:Page', 'Create:Page']);
    test()->actingAs($author);

    $component = Livewire::test(CreatePage::class)->assertSuccessful();

    $author->revokePermissionTo('Create:Page');

    $component->set('data.name', 'Created after revocation')->assertForbidden();

    expect(Page::query()->where('name', 'Created after revocation')->exists())->toBeFalse();
});
