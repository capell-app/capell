<?php

declare(strict_types=1);

use Capell\Admin\Data\Pages\PublishPanelViewData;
use Capell\Admin\Filament\Livewire\PublishStatusPanel;
use Capell\Admin\Tests\Fixtures\Publishing\SharedStatusRecordPolicy;
use Capell\Admin\Tests\Fixtures\Publishing\StatusOnlyRecord;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Permissions\SiteAccess;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

use function Filament\get_authorization_response;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    Schema::create('status_only_records', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('site_id')->nullable();
        $table->boolean('status')->default(true);
    });

    Permission::findOrCreate(SharedStatusRecordPolicy::VIEW_PERMISSION);
    Permission::findOrCreate(SharedStatusRecordPolicy::UPDATE_PERMISSION);
});

function sharedPanelActor(?Site $site, bool $canUpdate = true): User
{
    $user = test()->createUserWithPermission(SharedStatusRecordPolicy::VIEW_PERMISSION);
    $user->assignedSiteIds = $site instanceof Site ? collect([(int) $site->getKey()]) : collect();

    if ($canUpdate) {
        $user->givePermissionTo(SharedStatusRecordPolicy::UPDATE_PERMISSION);
    }

    expect(SiteAccess::forActor($user)->isGlobal())->toBeFalse()
        ->and($user->getAssignedSiteIds()->all())->toBe($site instanceof Site ? [(int) $site->getKey()] : []);

    test()->actingAs($user);

    return $user;
}

it('renders a shared statusable record permitted by its registered policy for a site editor', function (): void {
    $actor = sharedPanelActor(Site::factory()->create());
    Gate::policy(StatusOnlyRecord::class, SharedStatusRecordPolicy::class);
    $record = StatusOnlyRecord::query()->create(['site_id' => null, 'status' => true]);

    expect(Gate::forUser($actor)->allows('view', $record))->toBeTrue()
        ->and(SiteAccess::forActor($actor)->query(StatusOnlyRecord::class)->find($record->getKey()))->toBeNull();

    Livewire::test(PublishStatusPanel::class, ['recordClass' => StatusOnlyRecord::class, 'recordId' => $record->getKey()])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::publish_panel.status_active'))
        ->assertActionVisible('toggleStatus')
        ->callAction('toggleStatus');

    expect($record->fresh()?->isEnabled())->toBeFalse();
});

it('denies a foreign-site record through the registered view policy', function (): void {
    $actor = sharedPanelActor(Site::factory()->create());
    Gate::policy(StatusOnlyRecord::class, SharedStatusRecordPolicy::class);
    $record = StatusOnlyRecord::query()->create(['site_id' => Site::factory()->create()->getKey(), 'status' => true]);

    expect(Gate::forUser($actor)->allows('view', $record))->toBeFalse();

    Livewire::test(PublishStatusPanel::class, ['recordClass' => StatusOnlyRecord::class, 'recordId' => $record->getKey()])
        ->assertForbidden();

    expect($record->fresh()?->isEnabled())->toBeTrue();
});

it('denies a record with no policy even when Filament would allow it', function (bool $global): void {
    if ($global) {
        test()->actingAsAdmin();
    } else {
        sharedPanelActor(Site::factory()->create());
    }

    $record = StatusOnlyRecord::query()->create(['site_id' => null, 'status' => true]);

    expect(Gate::getPolicyFor($record))->toBeNull()
        ->and(Gate::allows('view', $record))->toBe($global)
        ->and(get_authorization_response('view', $record)->allowed())->toBeTrue();

    Livewire::test(PublishStatusPanel::class, ['recordClass' => StatusOnlyRecord::class, 'recordId' => $record->getKey()])
        ->assertForbidden();
})->with(['site editor' => false, 'global administrator' => true]);

it('denies a policy-less record even when a generic view ability allows it', function (): void {
    sharedPanelActor(Site::factory()->create());
    Gate::define('view', fn (): bool => true);
    $record = StatusOnlyRecord::query()->create(['site_id' => null, 'status' => true]);

    expect(Gate::getPolicyFor($record))->toBeNull()
        ->and(Gate::allows('view', $record))->toBeTrue();

    Livewire::test(PublishStatusPanel::class, ['recordClass' => StatusOnlyRecord::class, 'recordId' => $record->getKey()])
        ->assertForbidden();
});

it('denies a shared record to an actor with no site assignment or global role', function (): void {
    $actor = sharedPanelActor(null);
    Gate::policy(StatusOnlyRecord::class, SharedStatusRecordPolicy::class);
    $record = StatusOnlyRecord::query()->create(['site_id' => null, 'status' => true]);

    expect(Gate::forUser($actor)->allows('view', $record))->toBeFalse();

    Livewire::test(PublishStatusPanel::class, ['recordClass' => StatusOnlyRecord::class, 'recordId' => $record->getKey()])
        ->assertForbidden();
});

it('rejects an invalid record class before any database query', function (string $recordClass): void {
    test()->actingAsAdmin();
    $translation = Page::factory()->create()->translations()->save(Translation::factory()->make());
    $component = new PublishStatusPanel;
    $component->mount(Page::class, (int) $translation->getKey());
    new ReflectionProperty(PublishStatusPanel::class, 'recordClass')->setValue($component, $recordClass);
    $connection = DB::connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    try {
        expect(fn (): PublishPanelViewData => $component->viewData())->toThrow(InvalidArgumentException::class);
        expect($connection->getQueryLog())->toBeEmpty();
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }
})->with(['non-model' => stdClass::class, 'non-publishable model' => Translation::class]);

it('keeps both record identifiers locked against client updates', function (string $property): void {
    test()->actingAsAdmin();
    Gate::policy(StatusOnlyRecord::class, SharedStatusRecordPolicy::class);
    $record = StatusOnlyRecord::query()->create(['site_id' => null, 'status' => true]);
    $panel = Livewire::test(PublishStatusPanel::class, ['recordClass' => StatusOnlyRecord::class, 'recordId' => $record->getKey()]);

    expect(fn () => $panel->set($property, $property === 'recordClass' ? Site::class : $record->getKey() + 1))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['recordClass', 'recordId']);

it('keeps a missing record not found', function (): void {
    test()->actingAsAdmin();
    Gate::policy(StatusOnlyRecord::class, SharedStatusRecordPolicy::class);
    $panel = new PublishStatusPanel;
    $panel->recordClass = StatusOnlyRecord::class;
    $panel->recordId = 999999;

    expect(fn (): mixed => new ReflectionMethod($panel, 'record')->invoke($panel))
        ->toThrow(ModelNotFoundException::class);
});

it('preserves global administrator access to shared records with a policy', function (): void {
    test()->actingAsAdmin();
    Gate::policy(StatusOnlyRecord::class, SharedStatusRecordPolicy::class);
    $record = StatusOnlyRecord::query()->create(['site_id' => null, 'status' => true]);

    Livewire::test(PublishStatusPanel::class, ['recordClass' => StatusOnlyRecord::class, 'recordId' => $record->getKey()])
        ->assertSuccessful()
        ->assertActionVisible('toggleStatus');
});

it('renders for a viewer while keeping update controls and direct mutations denied', function (): void {
    sharedPanelActor(Site::factory()->create(), canUpdate: false);
    Gate::policy(StatusOnlyRecord::class, SharedStatusRecordPolicy::class);
    $record = StatusOnlyRecord::query()->create(['site_id' => null, 'status' => true]);
    $panel = Livewire::test(PublishStatusPanel::class, ['recordClass' => StatusOnlyRecord::class, 'recordId' => $record->getKey()])
        ->assertSuccessful()
        ->assertActionHidden('toggleStatus');

    $panel->instance()->toggleStatusAction()->call();

    expect($record->fresh()?->isEnabled())->toBeTrue();
});

it('rechecks view permission when an already mounted action is invoked', function (): void {
    $actor = sharedPanelActor(Site::factory()->create());
    Gate::policy(StatusOnlyRecord::class, SharedStatusRecordPolicy::class);
    $record = StatusOnlyRecord::query()->create(['site_id' => null, 'status' => true]);
    $component = Livewire::test(PublishStatusPanel::class, ['recordClass' => StatusOnlyRecord::class, 'recordId' => $record->getKey()])->instance();
    $actor->revokePermissionTo(SharedStatusRecordPolicy::VIEW_PERMISSION);

    expect(fn () => $component->toggleStatusAction()->call())->toThrow(AuthorizationException::class)
        ->and($record->fresh()?->isEnabled())->toBeTrue();
});

it('keeps foreign pages denied by PagePolicy while an assigned-site page is allowed', function (): void {
    $site = Site::factory()->create();
    $actor = sharedPanelActor($site);
    Permission::findOrCreate('View:Page');
    $actor->givePermissionTo('View:Page');
    $ownPage = Page::factory()->create(['site_id' => $site->getKey()]);
    $foreignPage = Page::factory()->create(['site_id' => Site::factory()->create()->getKey()]);

    Livewire::test(PublishStatusPanel::class, ['recordClass' => Page::class, 'recordId' => $ownPage->getKey()])
        ->assertSuccessful();

    Livewire::test(PublishStatusPanel::class, ['recordClass' => Page::class, 'recordId' => $foreignPage->getKey()])
        ->assertForbidden();
});
