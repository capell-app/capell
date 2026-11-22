<?php

declare(strict_types=1);

use Capell\Admin\Actions\Activity\RevertActivityAction;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Resources\Activities\ActivityResource;
use Capell\Admin\Filament\Resources\Activities\Pages\ListActivities;
use Capell\Admin\Filament\Resources\Activities\Tables\ActivitiesTable;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->site = Site::factory()->createOne();
    $this->role = Role::findOrCreate('activity-restricted-role', 'web');
    $this->blueprint = Blueprint::factory()->page()->create();
    $this->blueprint->roleRestrictions()->create(['role_id' => $this->role->getKey()]);
    $this->restrictedPage = Page::factory()->for($this->site)->create([
        'blueprint_id' => $this->blueprint->getKey(),
        'name' => 'Restricted page',
    ]);
    $this->openPage = Page::factory()->for($this->site)->create(['name' => 'Open page']);

    $this->restrictedActivity = pageActivity($this->restrictedPage, 'name', 'Before', 'Restricted page');
    $this->openActivity = pageActivity($this->openPage, 'name', 'Before', 'Open page');
});

function pageActivity(Page $page, string $field, string $old, string $new): Activity
{
    $activity = activity()
        ->performedOn($page)
        ->event('updated')
        ->withProperties(['old' => [$field => $old], 'attributes' => [$field => $new]])
        ->log('updated page');

    throw_unless($activity instanceof Activity, LogicException::class);

    return $activity;
}

function siteActivityActor(Site $site, bool $withRestrictedRole): User
{
    $actor = User::factory()->createOne();
    $actor->assignedSiteIds = collect([(int) $site->getKey()]);

    foreach ([
        ResourceEnum::Activity->permission('view_any'),
        ResourceEnum::Activity->permission('view'),
        CapellPermission::RevertActivityLog->name(),
        'Update:Page',
        'View:Page',
        'ViewAny:Page',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $actor->givePermissionTo($permission);
    }

    if ($withRestrictedRole) {
        $actor->assignRole('activity-restricted-role');
    }

    return $actor;
}

it('hides activity on pages a blueprint role restriction closes to the actor', function (): void {
    test()->actingAs(siteActivityActor($this->site, withRestrictedRole: false));

    Livewire::test(ListActivities::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$this->openActivity])
        ->assertCanNotSeeTableRecords([$this->restrictedActivity]);

    $visible = ActivityResource::getEloquentQuery()->pluck('id')->all();

    expect($visible)->toContain($this->openActivity->id)
        ->and($visible)->not->toContain($this->restrictedActivity->id);
});

it('hides activity on a restricted page through its translations', function (): void {
    [$activity] = translationActivity($this->restrictedPage);

    test()->actingAs(siteActivityActor($this->site, withRestrictedRole: false));
    expect(ActivityResource::getEloquentQuery()->pluck('id')->all())->not->toContain($activity->id);

    test()->actingAs(siteActivityActor($this->site, withRestrictedRole: true));
    expect(ActivityResource::getEloquentQuery()->pluck('id')->all())->toContain($activity->id);
});

it('shows restricted page activity to an actor holding the restricted role', function (): void {
    test()->actingAs(siteActivityActor($this->site, withRestrictedRole: true));

    Livewire::test(ListActivities::class)
        ->assertCanSeeTableRecords([$this->openActivity, $this->restrictedActivity]);
});

it('refuses the details of a restricted page activity', function (): void {
    test()->actingAs(siteActivityActor($this->site, withRestrictedRole: false));

    $restricted = ActivitiesTable::viewDetailsAction()->record($this->restrictedActivity);
    $open = ActivitiesTable::viewDetailsAction()->record($this->openActivity);

    expect(fn (): View|Htmlable|null => $restricted->getModalContent())->toThrow(AuthorizationException::class)
        ->and($open->getModalContent())->not->toBeNull();

    test()->actingAs(siteActivityActor($this->site, withRestrictedRole: true));

    expect(ActivitiesTable::viewDetailsAction()->record($this->restrictedActivity)->getModalContent())->not->toBeNull();
});

/** @return array{0: Activity, 1: Translation} */
function translationActivity(Page $page): array
{
    $translation = Translation::factory()->translatable($page)->createOne(['title' => 'Current title']);
    $activity = activity()->performedOn($translation)->event('updated')
        ->withProperties(['old' => ['title' => 'Earlier title'], 'attributes' => ['title' => 'Current title']])
        ->log('updated translation');

    throw_unless($activity instanceof Activity, LogicException::class);

    return [$activity, $translation];
}

it('refuses a forged revert on a restricted page and leaves its fields unchanged', function (): void {
    [$restrictedActivity, $restrictedTranslation] = translationActivity($this->restrictedPage);
    [$openActivity, $openTranslation] = translationActivity($this->openPage);

    test()->actingAs(siteActivityActor($this->site, withRestrictedRole: false));

    $pageResult = RevertActivityAction::run($this->restrictedActivity, ['name']);
    $translationResult = RevertActivityAction::run($restrictedActivity, ['title']);

    expect($pageResult->successful)->toBeFalse()
        ->and($pageResult->skippedFields)->toBe(['unauthorized' => ['name']])
        ->and($translationResult->successful)->toBeFalse()
        ->and($translationResult->skippedFields)->toBe(['unauthorized' => ['title']])
        ->and($this->restrictedPage->refresh()->name)->toBe('Restricted page')
        ->and($restrictedTranslation->refresh()->title)->toBe('Current title');

    expect(RevertActivityAction::run($openActivity, ['title'])->successful)->toBeTrue()
        ->and($openTranslation->refresh()->title)->toBe('Earlier title');
});

it('lets an actor holding the restricted role revert on a restricted page', function (): void {
    [$restrictedActivity, $restrictedTranslation] = translationActivity($this->restrictedPage);

    test()->actingAs(siteActivityActor($this->site, withRestrictedRole: true));

    expect(RevertActivityAction::run($restrictedActivity, ['title'])->successful)->toBeTrue()
        ->and($restrictedTranslation->refresh()->title)->toBe('Earlier title');
});

it('selects the same pages in SQL as the per-page accessibility check', function (): void {
    $without = siteActivityActor($this->site, withRestrictedRole: false);
    $with = siteActivityActor($this->site, withRestrictedRole: true);

    foreach ([$without, $with] as $actor) {
        $ids = Page::query()->accessibleByUser($actor)->pluck('id')->sort()->values()->all();
        $expected = Page::query()->with('blueprint.roleRestrictions', 'site')->get()
            ->filter(fn (Page $page): bool => $page->isAccessibleByUser($actor))
            ->pluck('id')->sort()->values()->all();

        expect($ids)->toBe($expected);
    }

    expect(Page::query()->accessibleByUser($without)->pluck('id')->all())->not->toContain($this->restrictedPage->id)
        ->and(Page::query()->accessibleByUser($with)->pluck('id')->all())->toContain($this->restrictedPage->id);
});
