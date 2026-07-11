<?php

declare(strict_types=1);

use Capell\Admin\Filament\Widgets\Dashboard\RecentActivityFilamentWidget;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(CreatesAdminUser::class)
    ->group('widget');

beforeEach(function (): void {
    Role::findOrCreate(config('capell.roles.editor', 'editor'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-06 14:40:00'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders the recent activity table for an authenticated editor', function (): void {
    $user = $this->createUser();
    $user->assignRole(config('capell.roles.editor', 'editor'));

    $this->actingAs($user);
    activity()
        ->causedBy($user)
        ->performedOn($user)
        ->event('created')
        ->withProperties(['attributes' => ['name' => $user->name]])
        ->log('created user');

    expect(RecentActivityFilamentWidget::canView())->toBeTrue();

    Livewire::test(RecentActivityFilamentWidget::class)
        ->assertOk()
        ->assertSee(__('capell-admin::dashboard.widget_activity_log'))
        ->assertTableHeaderActionsExistInOrder(['view-all'])
        ->assertTableActionExists('viewActivity', record: Activity::query()->first())
        ->assertSee('User #' . $user->name)
        ->assertSee('Created')
        ->assertSee('created user')
        ->assertSee('0 seconds ago · ' . $user->name . ' · created user')
        ->assertSee($user->name);
});

it('renders translation activity with the page and language in the primary row', function (): void {
    $user = $this->createUser();
    $user->assignRole(config('capell.roles.editor', 'editor'));

    $language = Language::factory()->english()->create();
    $page = Page::factory()->state(['name' => 'Home'])->create();
    $translation = Translation::factory()
        ->language($language)
        ->translatable($page)
        ->state(['title' => 'Home'])
        ->create();

    Activity::query()->delete();

    // Log without an authenticated user so the activity has no causer and the
    // widget renders it as "System"; authenticate afterwards only for rendering.
    activity()
        ->performedOn($translation)
        ->event('created')
        ->log('created');

    $this->actingAs($user);

    Livewire::test(RecentActivityFilamentWidget::class)
        ->assertOk()
        ->assertSee('Home Translation (English)')
        ->assertSee('Created')
        ->assertSee('0 seconds ago · System')
        ->assertDontSee('Translation for Home')
        ->assertDontSee('0 seconds ago · System · created (English)');
});

it('is hidden when there is no recent activity', function (): void {
    $user = $this->createUser();
    $user->assignRole(config('capell.roles.editor', 'editor'));

    $this->actingAs($user);
    Activity::query()->delete();

    expect(RecentActivityFilamentWidget::canView())->toBeFalse();
});
