<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Tables\Actions\EditAction;
use Capell\Admin\Filament\Resources\Layouts\Pages\EditLayout;
use Capell\Admin\Filament\Resources\Layouts\Pages\ListLayouts;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Filament\Resources\PageUrls\Pages\ManagePageUrls;
use Capell\Admin\Filament\Resources\PageUrls\PageUrlResource;
use Capell\Admin\Filament\Resources\Roles\Pages\ListRoles;
use Capell\Admin\Filament\Resources\Sites\Pages\EditSite;
use Capell\Admin\Filament\Resources\Sites\Pages\ListSites;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Filament\Resources\Users\Pages\ListUsers;
use Capell\Admin\Tests\Fixtures\Models\SiteScopedFormTestUser;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Tests\Fixtures\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    expect(Filament::isAuthorizationStrict())->toBeTrue();
});

function strictActionRecord(string $subject, Site $site): Model
{
    $record = match ($subject) {
        'User' => strictActionActor($site),
        'Role' => Role::findOrCreate('action-target', 'web'),
        'Site' => $site,
        'Layout' => Layout::factory()->createOne(['site_id' => $site->id]),
        'Page' => Page::factory()->site($site)->withTranslations()->createOne(),
        default => throw new InvalidArgumentException($subject),
    };

    if ($record instanceof SiteScopedFormTestUser) {
        // User table scoping resolves site membership through role assignments.
        $record->roles()->attach(Role::findOrCreate('action-site-member', 'web'), ['team_id' => $site->id]);
    }

    return $record;
}

function strictActionActor(Site $site): SiteScopedFormTestUser
{
    config()->set('auth.providers.users.model', SiteScopedFormTestUser::class);
    $actor = new SiteScopedFormTestUser;
    $actor->forceFill(User::factory()->raw());
    $actor->save();
    SiteScopedFormTestUser::rememberAssignedSiteIds((int) $actor->getKey(), collect([$site->id]));

    return $actor;
}

it('maps the shared edit action to update without granting an edit-only permission', function (string $subject): void {
    $site = Site::factory()->createOne();
    $actor = strictActionActor($site);
    $record = strictActionRecord($subject, $site);
    test()->actingAs($actor);
    $actor->givePermissionTo(Permission::findOrCreate('Edit:' . $subject, 'web'));

    expect(EditAction::make()->record($record)->isAuthorized())->toBeFalse();

    // The host deliberately supplies a Role policy with update(), but no edit().
    $permission = $subject === 'Role' ? 'update_role' : 'Update:' . $subject;
    $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));

    expect(EditAction::make()->record($record)->isAuthorized())->toBeTrue();
})->with(['User', 'Role', 'Site', 'Layout', 'Page']);

it('renders record edit actions using the existing update policy under strict mode', function (string $subject, string $list): void {
    $site = Site::factory()->createOne();
    $actor = strictActionActor($site);
    $record = strictActionRecord($subject, $site);
    $viewPermission = $subject === 'Role' ? 'view_any_role' : 'ViewAny:' . $subject;
    $actor->givePermissionTo(Permission::findOrCreate($viewPermission, 'web'));
    test()->actingAs($actor);

    Livewire::test($list)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$record])
        ->assertTableActionHidden('edit', $record);

    $updatePermission = $subject === 'Role' ? 'update_role' : 'Update:' . $subject;
    $actor->givePermissionTo(Permission::findOrCreate($updatePermission, 'web'));

    Livewire::test($list)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$record])
        ->assertTableActionVisible('edit', $record);
})->with([
    ['User', ListUsers::class],
    ['Role', ListRoles::class],
    ['Site', ListSites::class],
    ['Layout', ListLayouts::class],
    ['Page', ListPages::class],
]);

it('renders replicate headers and invokes restore actions with separate permissions under strict mode', function (string $subject, string $edit): void {
    $site = Site::factory()->createOne();
    $record = strictActionRecord($subject, $site);
    $actor = strictActionActor($site);
    $actor->givePermissionTo([
        Permission::findOrCreate('Update:' . $subject, 'web'),
        Permission::findOrCreate('ViewAny:' . $subject, 'web'),
    ]);
    test()->actingAs($actor);

    Livewire::test($edit, ['record' => $record->getRouteKey()])
        ->assertSuccessful()
        ->assertActionHidden('replicate');

    $actor->givePermissionTo(Permission::findOrCreate('Replicate:' . $subject, 'web'));
    // Replicating a site creates one, so the Site policy also requires its create permission.
    if ($subject === 'Site') {
        $actor->givePermissionTo(Permission::findOrCreate('Create:Site', 'web'));
    }

    Livewire::test($edit, ['record' => $record->getRouteKey()])
        ->assertSuccessful()
        ->assertActionVisible('replicate')
        ->mountAction('replicate')
        ->assertActionMounted('replicate');

    $record->delete();

    expect($record->fresh())->not->toBeNull()
        ->and(Gate::allows('view', $record))->toBeTrue();

    if ($record instanceof Page) {
        Livewire::test(ListPages::class)
            ->filterTable('trashed', true)
            ->assertTableBulkActionHidden('restore');

        $actor->givePermissionTo([
            Permission::findOrCreate('Restore:Page', 'web'),
            Permission::findOrCreate('RestoreAny:Page', 'web'),
        ]);

        Livewire::test(ListPages::class)
            ->filterTable('trashed', true)
            ->assertTableBulkActionVisible('restore')
            ->callTableBulkAction('restore', [$record]);
    } else {
        Livewire::test($edit, ['record' => $record->getRouteKey()])
            ->assertSuccessful()
            ->assertActionHidden('restore');

        $actor->givePermissionTo(Permission::findOrCreate('Restore:' . $subject, 'web'));

        Livewire::test($edit, ['record' => $record->getRouteKey()])
            ->assertSuccessful()
            ->assertActionVisible('restore')
            ->callAction('restore');
    }

    $restored = expectPresent($record->fresh());
    expect($restored->getAttribute('deleted_at'))->toBeNull();
})->with([
    ['Site', EditSite::class],
    ['Layout', EditLayout::class],
    ['Page', EditPage::class],
]);

it('uses a dedicated scoped permission for every exposed replication ability', function (string $resource, string $subject): void {
    $site = Site::factory()->createOne();
    $foreign = Site::factory()->createOne();
    $actor = strictActionActor($site);
    test()->actingAs($actor);
    $record = $subject === 'Site' ? $site : PageUrl::factory()->manualRedirect()->site($site)->createOne();
    $foreignRecord = $subject === 'Site' ? $foreign : PageUrl::factory()->manualRedirect()->site($foreign)->createOne();

    expect($resource::canReplicate($record))->toBeFalse();
    $actor->givePermissionTo(Permission::findOrCreate('Update:' . $subject, 'web'));
    expect($resource::canReplicate($record))->toBeFalse();
    $actor->givePermissionTo(Permission::findOrCreate('Replicate:' . $subject, 'web'));
    // Replicating a site creates one, so the Site policy also requires its create permission.
    if ($subject === 'Site') {
        $actor->givePermissionTo(Permission::findOrCreate('Create:Site', 'web'));
    }

    expect($resource::canReplicate($record))->toBeTrue()
        ->and($resource::canReplicate($foreignRecord))->toBeFalse();
})->with([
    [SiteResource::class, 'Site'],
    [PageUrlResource::class, 'PageUrl'],
]);

it('renders and mounts page-url replication under strict mode only with its permission', function (): void {
    $site = Site::factory()->withTranslations()->createOne();
    $page = Page::factory()->site($site)->withTranslations()->createOne();
    $record = $page->pageUrls()->firstOrFail();
    $actor = strictActionActor($site);
    $actor->givePermissionTo(Permission::findOrCreate('ViewAny:PageUrl', 'web'));

    test()->actingAs($actor);

    Livewire::test(ManagePageUrls::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$record])
        ->assertTableActionHidden('replicate', $record);

    $actor->givePermissionTo(Permission::findOrCreate('Replicate:PageUrl', 'web'));

    Livewire::test(ManagePageUrls::class)
        ->assertSuccessful()
        ->assertTableActionVisible('replicate', $record)
        ->mountAction(TestAction::make('replicate')->table($record))
        ->assertActionMounted(TestAction::make('replicate')->table($record));
});

it('preserves a host policy denial when resolving edit actions', function (): void {
    $record = User::factory()->createOne();
    $actor = User::factory()->createOne();
    test()->actingAs($actor);
    $actor->givePermissionTo(Permission::findOrCreate('Update:User', 'web'));
    $policy = new class
    {
        public function update(User $user, User $record): bool
        {
            return false;
        }
    };
    Gate::policy(User::class, $policy::class);

    expect(EditAction::make()->record($record)->isAuthorized())->toBeFalse();
});

it('preserves read-only relation manager denial even when update is allowed', function (): void {
    $actor = User::factory()->createOne();
    test()->actingAs($actor);
    expect(EditAction::make()->record($actor)->isAuthorized())->toBeTrue();

    $manager = Mockery::mock(RelationManager::class)->makePartial();
    $manager->shouldReceive('isReadOnly')->andReturnTrue();

    expect(EditAction::make()->record($actor)->livewire($manager)->isAuthorized())->toBeFalse();
});

it('preserves the owning components edit authorisation response', function (bool $relationManager): void {
    $actor = User::factory()->createOne();
    test()->actingAs($actor);
    expect(EditAction::make()->record($actor)->isAuthorized())->toBeTrue();

    $owner = $relationManager
        ? Mockery::mock(RelationManager::class)->makePartial()
        : Mockery::mock(ListUsers::class)->makePartial();
    if ($owner instanceof RelationManager) {
        $owner->shouldReceive('isReadOnly')->andReturnFalse();
    }

    $denial = Response::deny('Editing is disabled by the owner.');
    $owner->shouldReceive('getDefaultActionAuthorizationResponse')->andReturn($denial);
    $action = EditAction::make()->record($actor)->livewire($owner);

    expect($action->isAuthorized())->toBeFalse()
        ->and($action->getAuthorizationResponse())->toBe($denial);
})->with([false, true]);
