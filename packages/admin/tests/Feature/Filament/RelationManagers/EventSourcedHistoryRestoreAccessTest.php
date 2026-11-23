<?php

declare(strict_types=1);

use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Filament\RelationManagers\EventSourcedHistoryRelationManager;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Tests\Support\Models\TeamScopedPanelUser;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Filament\Actions\Testing\TestAction;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses()->group('admin');

beforeEach(function (): void {
    config(['permission.teams' => true]);
    resolve(PermissionRegistrar::class)->teams = true;

    $this->siteA = Site::factory()->withTranslations()->create();
    $this->siteB = Site::factory()->withTranslations()->create();

    $this->editor = TeamScopedPanelUser::query()->create(['name' => 'Multi-site editor', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);

    // Site A: may edit pages but may not restore versions. Site B: may do both.
    $edit = ['View:Page', 'Update:Page'];
    $editOnly = Role::query()->firstOrCreate(['name' => 'history-editor', 'guard_name' => 'web']);
    $editOnly->syncPermissions(array_map(fn (string $name): Permission => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']), $edit));

    $restorer = Role::query()->firstOrCreate(['name' => 'history-restorer', 'guard_name' => 'web']);
    $restorer->syncPermissions(array_map(fn (string $name): Permission => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']), [...$edit, CapellPermission::RollbackPage->name()]));

    $this->editor->assignRoleForSite($this->siteA, $editOnly);
    $this->editor->assignRoleForSite($this->siteB, $restorer);

    $this->page = Page::factory()->site($this->siteA)->create();
    $this->translation = Translation::factory()->translatable($this->page)->language(Language::factory()->createOne())
        ->create(['title' => 'First', 'content' => '<p>first</p>']);
    $this->page->load(['translations', 'pageUrls']);
    $this->page->save();

    $this->target = PageRevision::query()->where('page_uuid', $this->page->uuid)->orderByDesc('version')->firstOrFail();
    $this->translation->forceFill(['title' => 'Second'])->save();
    $this->page->load(['translations', 'pageUrls']);
    $this->page->save();

    $this->latest = PageRevision::query()->where('page_uuid', $this->page->uuid)->orderByDesc('version')->firstOrFail();

    setPermissionsTeamId(null);
});

afterEach(function (): void {
    setPermissionsTeamId(null);
    resolve(PermissionRegistrar::class)->teams = false;
    config(['permission.teams' => false]);
});

function restoreAccessHistory(Page $page): Testable
{
    return Livewire::test(EventSourcedHistoryRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ]);
}

it('refuses a restore and a compare on a page whose own site does not grant the rollback permission', function (): void {
    // The session has site B selected, where the rollback permission is held.
    setPermissionsTeamId($this->siteB->getKey());
    test()->actingAs($this->editor);

    $component = restoreAccessHistory($this->page);
    $table = $component->instance()->getTable();

    // A forged call skips the hidden buttons, so the server-side checks decide.
    $component->assertActionHidden(TestAction::make('rollback')->table($this->target));

    expect($table->getAction('rollback')?->record($this->target)->isAuthorized())->toBeFalse()
        ->and($table->getAction('compare')?->record($this->latest)->isAuthorized())->toBeFalse()
        ->and($this->translation->fresh()->title)->toBe('Second')
        ->and(PageRevision::query()->where('page_uuid', $this->page->uuid)->where('is_rollback', true)->exists())->toBeFalse();
});

it('lets an editor who holds the rollback permission on the page site restore it from another selected site', function (): void {
    $this->editor->assignRoleForSite($this->siteA, 'history-restorer');
    setPermissionsTeamId($this->siteB->getKey());
    test()->actingAs($this->editor);

    $table = restoreAccessHistory($this->page)->instance()->getTable();

    expect($table->getAction('rollback')?->record($this->target)->isAuthorized())->toBeTrue()
        ->and($table->getAction('compare')?->record($this->latest)->isAuthorized())->toBeTrue();

    restoreAccessHistory($this->page)
        ->callAction(TestAction::make('rollback')->table($this->target))
        ->assertNotified();

    expect($this->translation->fresh()->title)->toBe('First');
});

it('refuses a restore when the page policy denies update even though the rollback permission is held', function (): void {
    $noUpdate = Role::query()->firstOrCreate(['name' => 'history-restore-only', 'guard_name' => 'web']);
    $noUpdate->syncPermissions([Permission::query()->firstOrCreate(['name' => 'View:Page', 'guard_name' => 'web']), Permission::query()->firstOrCreate(['name' => CapellPermission::RollbackPage->name(), 'guard_name' => 'web'])]);

    $this->editor->removeRoleForSite($this->siteA, 'history-editor');
    $this->editor->assignRoleForSite($this->siteA, $noUpdate);
    setPermissionsTeamId($this->siteA->getKey());
    test()->actingAs($this->editor);

    $table = restoreAccessHistory($this->page)->instance()->getTable();

    expect($table->getAction('rollback')?->record($this->target)->isAuthorized())->toBeFalse()
        ->and($table->getAction('compare')?->record($this->latest)->isAuthorized())->toBeFalse();
});
