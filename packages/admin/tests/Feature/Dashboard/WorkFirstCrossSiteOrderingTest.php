<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildWorkFirstActivityAction;
use Capell\Admin\Contracts\Dashboard\DashboardWorkDataProvider;
use Capell\Admin\Data\Dashboard\DashboardWorkData;
use Capell\Admin\Data\Dashboard\DashboardWorkItemData;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\PersonalDraftsWidget;
use Capell\Admin\Support\Dashboard\DashboardDeadline;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\Activitylog\Models\Activity;

uses(CreatesAdminUser::class);

it('retains the activity group key when another matching change arrives', function (): void {
    $this->actingAsAdmin();
    $actor = $this->authenticatedUser();
    $page = Page::withoutEvents(fn () => Page::factory()->create());
    Activity::query()->delete();
    $attributes = [
        'log_name' => 'default', 'description' => 'updated', 'event' => 'updated',
        'subject_type' => $page->getMorphClass(), 'subject_id' => $page->id,
        'causer_type' => $actor->getMorphClass(), 'causer_id' => $actor->id,
        'properties' => ['attributes' => ['name' => 'Changed']], 'created_at' => now()->startOfHour(),
    ];
    Activity::query()->create($attributes);
    $action = resolve(BuildWorkFirstActivityAction::class);
    $before = $action->handle($actor, $page->site_id, null);
    Activity::query()->create($attributes);
    $after = $action->handle($actor, $page->site_id, null);
    expect($before)->toHaveCount(1)->and($after)->toHaveCount(1)
        ->and($after[0]->key)->toBe($before[0]->key)->and($after[0]->changes)->toBe(2);
});

it('orders combined work by deadline and requested changes', function (): void {
    $this->actingAsAdmin();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    app()->instance(DashboardWorkDataProvider::class, new class implements DashboardWorkDataProvider
    {
        #[Override]
        /** @param list<int> $retainedPageIds Authorised rows to retain beyond the new-item preview limit. */
        public function build(Authenticatable $actor, ?int $siteId, int $limit, ?string $language = null, array $retainedPageIds = []): DashboardWorkData
        {
            return new DashboardWorkData(true, 3, [
                new DashboardWorkItemData(3, 1, 'Site', 'Later', 'draft', '2026-12-03', null, null),
                new DashboardWorkItemData(2, 1, 'Site', 'Ordinary', 'draft', '2026-12-01', null, null),
                new DashboardWorkItemData(1, 1, 'Site', 'Changes requested', 'changes_requested', '2026-12-01', null, null),
            ]);
        }
    });
    expect(collect((new PersonalDraftsWidget)->workRows())->pluck('title')->all())
        ->toBe(['Changes requested', 'Ordinary', 'Later']);
});

it('compares deadline instants across different site time zones and ignores invalid dates', function (): void {
    $early = DashboardDeadline::timestamp('2026-10-29T10:00:00+02:00');
    $later = DashboardDeadline::timestamp('2026-10-29T09:30:00+00:00');
    expect($early)->toBeLessThan($later)
        ->and(DashboardDeadline::timestamp('invalid'))->toBe(PHP_INT_MAX)
        ->and(DashboardDeadline::timestamp(null))->toBe(PHP_INT_MAX);
});
