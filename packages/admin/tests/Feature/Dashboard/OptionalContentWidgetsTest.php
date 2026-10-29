<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildContentStatusAction;
use Capell\Admin\Actions\Dashboard\BuildRecentlyUpdatedAction;
use Capell\Admin\Data\Dashboard\DashboardRecentlyUpdatedItemData;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\ContentStatusWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\RecentlyUpdatedWidget;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

uses(CreatesAdminUser::class);

it('counts and lists only pages within the selected site', function (): void {
    test()->actingAsAdmin();
    $site = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    Page::factory()->createOne(['site_id' => $site->id, 'name' => 'Draft here', 'visible_from' => PublishSentinel::draftValue()]);
    Page::factory()->createOne(['site_id' => $otherSite->id, 'name' => 'Other site', 'visible_from' => now()->subDay()]);
    $actor = test()->authenticatedUser();
    $counts = resolve(BuildContentStatusAction::class)->handle($actor, $site->id, null);
    $data = resolve(BuildRecentlyUpdatedAction::class)->handle($actor, $site->id, null, 5);
    $items = $data->items;

    expect($counts['draft'])->toBe(1)->and($counts['published'])->toBe(0)
        ->and(array_map(static fn (DashboardRecentlyUpdatedItemData $item): string => $item->title, $items))->toBe(['Draft here'])
        ->and($items[0]->siteName)->toBe($site->name)->and($data->total)->toBe(1);
});

it('disables optional widget requests when switched off', function (): void {
    test()->actingAsAdmin();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    config()->set('capell-admin.dashboard.work_first.widgets', ['content_status' => false, 'recently_updated' => false]);
    expect(ContentStatusWidget::canView())->toBeFalse()->and(RecentlyUpdatedWidget::canView())->toBeFalse()
        ->and((new ContentStatusWidget)->counts())->toBe([])->and((new RecentlyUpdatedWidget)->data()->items)->toBe([]);
});

it('offers optional content widgets without adding them to the default layout', function (): void {
    test()->actingAsAdmin();
    Site::factory()->createOne();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $dashboard = new CapellDashboard;
    $items = new ReflectionMethod($dashboard, 'layoutFormState')->invoke($dashboard);
    $byKey = collect($items)->keyBy('key');

    expect($byKey->get(ContentStatusWidget::class)['visible'])->toBeFalse()
        ->and($byKey->get(RecentlyUpdatedWidget::class)['visible'])->toBeFalse();
});
