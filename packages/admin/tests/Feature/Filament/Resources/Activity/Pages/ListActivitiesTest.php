<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Activity\ActivityChangeSetBuilder;
use Capell\Admin\Filament\Resources\Activities\ActivityResource;
use Capell\Admin\Filament\Resources\Activities\Pages\ListActivities;
use Capell\Admin\Filament\Resources\Languages\LanguageResource;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Tests\Fixtures\Autoload\PackageActivityChangeSetBuilderForListTest;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(CreatesAdminUser::class)
    ->group('activity');

beforeEach(function (): void {
    Role::findOrCreate(config('capell.roles.editor', 'editor'));

    $user = $this->createUser();
    $user->assignRole(config('capell.roles.editor', 'editor'));

    $this->actingAs($user);
});

it('lists decorated activity rows', function (): void {
    $language = Language::factory()->createOne(['name' => 'French']);

    activity()
        ->performedOn($language)
        ->event('updated')
        ->withProperties([
            'old' => ['name' => 'Francais'],
            'attributes' => ['name' => 'French'],
        ])
        ->log('updated language');

    Livewire::test(ListActivities::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(Activity::query()->get())
        ->assertSee(__('capell-admin::activity.list_subheading'))
        ->assertSee('updated language')
        ->assertSee('Language #French')
        ->assertSeeHtml('href="' . e(LanguageResource::getUrl('index')) . '"');
});

it('links translation activity to the translatable resource', function (): void {
    $language = Language::factory()->createOne(['name' => 'French']);
    $page = Page::factory()->createOne(['name' => 'Translated page']);
    $translation = Translation::factory()
        ->language($language)
        ->translatable($page)
        ->createOne(['title' => 'Translated title']);

    activity()
        ->performedOn($translation)
        ->event('updated')
        ->withProperties([
            'old' => ['title' => 'Old translated title'],
            'attributes' => ['title' => 'Translated title'],
        ])
        ->log('updated translation');

    Livewire::test(ListActivities::class)
        ->assertSuccessful()
        ->assertSee('updated translation')
        ->assertSee('Translation')
        ->assertSeeHtml('href="' . e(PageResource::getUrl('edit', ['record' => $page])) . '"');
});

it('does not expose an open resource action for activity without a linked subject', function (): void {
    $activity = activity()
        ->event('updated')
        ->log('system activity without subject');

    Livewire::test(ListActivities::class)
        ->assertSuccessful()
        ->assertSee('system activity without subject')
        ->mountTableAction('viewActivity', $activity)
        ->assertMountedActionModalSee(__('capell-admin::activity.no_field_changes'))
        ->assertMountedActionModalSee(__('capell-admin::activity.no_change_details'))
        ->assertActionDoesNotExist('openSubject');
});

it('hides revert actions from users without the activity log revert permission', function (): void {
    $activity = activity()
        ->performedOn(Language::factory()->createOne(['name' => 'French']))
        ->event('updated')
        ->withProperties([
            'old' => ['name' => 'Francais'],
            'attributes' => ['name' => 'French'],
        ])
        ->log('updated language');

    Livewire::test(ListActivities::class)
        ->mountTableAction('viewActivity', $activity)
        ->assertActionDoesNotExist('revertActivity');
});

it('shows revert actions to users with the activity log revert permission', function (): void {
    Permission::findOrCreate('activity_log.revert');
    test()->authenticatedUser()->givePermissionTo('activity_log.revert');

    $activity = activity()
        ->performedOn(Language::factory()->createOne(['name' => 'French']))
        ->event('updated')
        ->withProperties([
            'old' => ['name' => 'Francais'],
            'attributes' => ['name' => 'French'],
        ])
        ->log('updated language');

    Livewire::test(ListActivities::class)
        ->mountTableAction('viewActivity', $activity)
        ->assertActionVisible('revertActivity');
});

it('reverts only selected fields from the activity modal', function (): void {
    Permission::findOrCreate('activity_log.revert');
    test()->authenticatedUser()->givePermissionTo('activity_log.revert');

    $language = Language::factory()->createOne([
        'name' => 'French',
        'code' => 'fr',
    ]);

    $activity = activity()
        ->performedOn($language)
        ->event('updated')
        ->withProperties([
            'old' => ['name' => 'Francais', 'code' => 'fra'],
            'attributes' => ['name' => 'French', 'code' => 'fr'],
        ])
        ->log('updated language');

    Livewire::test(ListActivities::class)
        ->mountTableAction('viewActivity', $activity)
        ->callAction('revertActivity', [
            'selectedPaths' => ['name'],
        ])
        ->assertNotified(__('capell-admin::activity.reverted'));

    expect($language->refresh()->name)->toBe('Francais')
        ->and($language->code)->toBe('fr');
});

it('shows skipped field summaries from modal revert notifications', function (): void {
    Permission::findOrCreate('activity_log.revert');
    test()->authenticatedUser()->givePermissionTo('activity_log.revert');

    $language = Language::factory()->createOne(['name' => 'French']);

    $activity = activity()
        ->performedOn($language)
        ->event('updated')
        ->withProperties([
            'old' => ['name' => 'Francais'],
            'attributes' => ['name' => 'French'],
        ])
        ->log('updated language');

    $language->update(['name' => 'French updated again']);

    Livewire::test(ListActivities::class)
        ->mountTableAction('viewActivity', $activity)
        ->callAction('revertActivity', [
            'selectedPaths' => ['name'],
        ])
        ->assertNotified(__('capell-admin::activity.revert_failed'));
});

it('renders package change set builders in the activity modal', function (): void {
    PackageActivityChangeSetBuilderForListTest::$buildCount = 0;
    app()->tag([PackageActivityChangeSetBuilderForListTest::class], ActivityChangeSetBuilder::TAG);

    $activity = activity()
        ->performedOn(Language::factory()->createOne(['name' => 'French']))
        ->event('updated')
        ->withProperties([
            'old' => ['name' => 'Francais'],
            'attributes' => ['name' => 'French'],
        ])
        ->log('updated language');

    Livewire::test(ListActivities::class)
        ->mountTableAction('viewActivity', $activity)
        ->assertMountedActionModalSee('Package summary')
        ->assertMountedActionModalSee('Package Headline');
});

it('extracts nested JSON differences in the activity modal', function (): void {
    $activity = activity()
        ->performedOn(Language::factory()->createOne(['name' => 'French']))
        ->event('updated')
        ->withProperties([
            'old' => [
                'nodes' => [
                    [
                        'title' => 'Publishing model',
                        'route' => 'platform',
                    ],
                ],
            ],
            'attributes' => [
                'nodes' => [
                    [
                        'title' => 'Editor journey',
                        'route' => 'platform',
                    ],
                    [
                        'title' => 'Reusable layouts',
                        'route' => 'layout-builder',
                    ],
                ],
            ],
        ])
        ->log('updated section');

    Livewire::test(ListActivities::class)
        ->mountTableAction('viewActivity', $activity)
        ->assertMountedActionModalSeeHtml('data-capell-activity-details')
        ->assertMountedActionModalSee(__('capell-admin::activity.changed_inside'))
        ->assertMountedActionModalSee('Nodes / #1 / Title')
        ->assertMountedActionModalSee('Publishing model')
        ->assertMountedActionModalSee('Editor journey')
        ->assertMountedActionModalSee('Nodes / #2 / Title')
        ->assertMountedActionModalSee('Reusable layouts');
});

it('caches change sets per activity row during table rendering', function (): void {
    Activity::query()->delete();
    PackageActivityChangeSetBuilderForListTest::$buildCount = 0;
    app()->tag([PackageActivityChangeSetBuilderForListTest::class], ActivityChangeSetBuilder::TAG);

    activity()
        ->performedOn(Language::factory()->createOne(['name' => 'French']))
        ->event('updated')
        ->withProperties([
            'old' => ['name' => 'Francais'],
            'attributes' => ['name' => 'French'],
        ])
        ->log('updated language');

    Livewire::test(ListActivities::class)
        ->assertSuccessful()
        ->assertSee('Package summary')
        ->assertSee('Package resource');

    expect(PackageActivityChangeSetBuilderForListTest::$buildCount)->toBe(1);
});

it('hides delete actions from users without the activity log delete permission', function (): void {
    $activity = activity()
        ->performedOn(Language::factory()->createOne(['name' => 'French']))
        ->event('updated')
        ->withProperties([
            'old' => ['name' => 'Francais'],
            'attributes' => ['name' => 'French'],
        ])
        ->log('updated language');

    Livewire::test(ListActivities::class)
        ->assertTableActionHidden('deleteActivity', record: $activity);
});

it('deletes activity log entries from the table for permitted users', function (): void {
    Permission::findOrCreate('activity_log.delete');
    test()->authenticatedUser()->givePermissionTo('activity_log.delete');

    $activity = activity()
        ->performedOn(Language::factory()->createOne(['name' => 'French']))
        ->event('updated')
        ->withProperties([
            'old' => ['name' => 'Francais'],
            'attributes' => ['name' => 'French'],
        ])
        ->log('updated language');

    assert($activity instanceof Activity);

    Livewire::test(ListActivities::class)
        ->assertTableActionVisible('deleteActivity', record: $activity)
        ->callTableAction('deleteActivity', record: $activity)
        ->assertNotified(__('capell-admin::activity.deleted'));

    expect(Activity::query()->whereKey($activity->getKey())->exists())->toBeFalse();
});

it('labels the activity trail as an activity log', function (): void {
    $navigationTranslations = require __DIR__ . '/../../../../../../resources/lang/en/navigation.php';
    $activityTranslations = require __DIR__ . '/../../../../../../resources/lang/en/activity.php';

    expect($navigationTranslations['activity_trail'])->toBe('Activity Log')
        ->and($activityTranslations['activities'])->toBe('Activity Log');
});

it('badges workspace activity in navigation', function (): void {
    activity()
        ->event('updated')
        ->withProperties(['workspace_id' => 123])
        ->log('updated workspace draft');

    activity()
        ->event('updated')
        ->withProperties(['workspace_id' => null])
        ->log('updated public page');

    expect(ActivityResource::getNavigationBadge())->toBe('1')
        ->and(ActivityResource::getNavigationBadgeColor())->toBe('warning');
});
