<?php

declare(strict_types=1);

use Capell\Admin\Enums\NavigationGroupEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Redirects\RedirectResource;
use Capell\Admin\Support\CapellAdminManager;
use Capell\Admin\Support\Navigation\AdminNavigationOrganiser;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

beforeEach(function (): void {
    CapellAdmin::clearResolvedInstance(CapellAdminManager::class);
});

/** @param list<NavigationGroup> $groups
 * @return array<string, array<int, string>>
 */
function organisedLabels(array $groups): array
{
    $labels = [];
    foreach ($groups as $group) {
        $labels[$group->getLabel() ?? ''] = collect($group->getItems())->map(fn (NavigationItem $item): string => $item->getLabel())->values()->all();
    }

    return $labels;
}

it('puts the dashboard first and every other item in its fixed group, in group order', function (): void {
    $groups = resolve(AdminNavigationOrganiser::class)->organise([
        NavigationGroup::make()->items([
            NavigationItem::make('Settings tool')->group(NavigationGroupEnum::Settings)->url('/admin/tool'),
            NavigationItem::make('Bookings')->group('Bookings')->url('/admin/bookings'),
            NavigationItem::make('Forms')->group(NavigationGroupEnum::Marketing)->url('/admin/forms'),
            NavigationItem::make('Dashboard')->key(CapellDashboard::class)->url('/admin'),
            NavigationItem::make('Menus')->group('capell-admin::navigation.group_websites')->url('/admin/menus'),
            NavigationItem::make('Pages')->key(PageResource::class)->group(NavigationGroupEnum::Website)->url('/admin/pages'),
        ]),
    ]);

    expect(organisedLabels($groups))->toBe([
        '' => ['Dashboard'],
        'Website' => ['Pages', 'Menus'],
        'Marketing' => ['Forms'],
        'Settings' => ['Bookings', 'Settings tool'],
    ])->and($groups[1]->isCollapsed())->toBeFalse()
        ->and($groups[2]->isCollapsed())->toBeTrue();
});

it('keeps core items in their fixed order and sorts package items by label, ignoring their sort numbers', function (): void {
    $groups = resolve(AdminNavigationOrganiser::class)->organise([
        NavigationGroup::make('Website')->items([
            NavigationItem::make('Tags')->group(NavigationGroupEnum::Website)->sort(-500)->url('/admin/tags'),
            NavigationItem::make('Redirects')->key(RedirectResource::class)->group(NavigationGroupEnum::Website)->sort(9)->url('/admin/redirects'),
            NavigationItem::make('Articles')->group(NavigationGroupEnum::Website)->sort(2)->url('/admin/articles'),
            NavigationItem::make('Pages')->key(PageResource::class)->group(NavigationGroupEnum::Website)->sort(50)->url('/admin/pages'),
        ]),
    ]);

    expect(organisedLabels($groups)['Website'])->toBe(['Pages', 'Redirects', 'Articles', 'Tags']);
});

it('shows a replacement in the replaced item place and hides the replaced item only when the replacement is present', function (): void {
    CapellAdmin::replaceNavigationItem(RedirectResource::class, 'package.redirects');
    $redirects = NavigationItem::make('Redirects')->key(RedirectResource::class)->group(NavigationGroupEnum::Website)->url('/admin/redirects');
    $pages = NavigationItem::make('Pages')->key(PageResource::class)->group(NavigationGroupEnum::Website)->url('/admin/pages');
    $articles = NavigationItem::make('Articles')->group(NavigationGroupEnum::Website)->url('/admin/articles');

    $withReplacement = resolve(AdminNavigationOrganiser::class)->organise([NavigationGroup::make()->items([
        $articles,
        NavigationItem::make('URL manager')->key('package.redirects')->group(NavigationGroupEnum::Website)->url('/admin/url-manager'),
        $redirects,
        $pages,
    ])]);
    $withoutReplacement = resolve(AdminNavigationOrganiser::class)->organise([NavigationGroup::make()->items([$redirects, $pages])]);

    expect(organisedLabels($withReplacement)['Website'])->toBe(['Pages', 'URL manager', 'Articles'])
        ->and(organisedLabels($withoutReplacement)['Website'])->toBe(['Pages', 'Redirects']);
});

it('moves items over the group limit under a More item', function (): void {
    config()->set('capell-admin.navigation.max_items_per_group', 3);
    $items = [];
    foreach (['A', 'B', 'C', 'D', 'E'] as $label) {
        $items[] = NavigationItem::make($label)->group(NavigationGroupEnum::Reports)->url('/admin/' . strtolower($label))->isActiveWhen(fn (): bool => $label === 'E');
    }

    $groups = resolve(AdminNavigationOrganiser::class)->organise([NavigationGroup::make()->items($items)]);
    $reportItems = collect($groups[0]->getItems());
    $more = $reportItems->last();

    expect($reportItems->map(fn (NavigationItem $item): string => $item->getLabel())->all())->toBe(['A', 'B', 'More'])
        ->and(collect($more?->getChildItems())->map(fn (NavigationItem $item): string => $item->getLabel())->all())->toBe(['C', 'D', 'E'])
        ->and($more?->getUrl())->toBe('/admin/c')
        ->and($more?->isActive())->toBeTrue();
});

it('keeps a child with no group of its own under its parent', function (): void {
    $groups = resolve(AdminNavigationOrganiser::class)->organise([
        NavigationGroup::make('Website')->items([NavigationItem::make('Articles')->key('test.articles')->group(NavigationGroupEnum::Website)->url('/admin/articles')]),
        NavigationGroup::make()->items([NavigationItem::make('Tags')->key('test.tags')->parentItem('Articles')->url('/admin/tags')]),
    ]);

    expect(organisedLabels($groups))->toBe(['Website' => ['Articles']])
        ->and(collect(collect($groups[0]->getItems())->first()?->getChildItems())->map(fn (NavigationItem $item): string => $item->getKey())->all())->toBe(['test.tags']);
});

it('produces no headings when nothing is visible', function (): void {
    expect(resolve(AdminNavigationOrganiser::class)->organise([]))->toBe([]);
});
