<?php

declare(strict_types=1);

use Capell\Admin\Support\Navigation\WorkspaceNavigation;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Translation\Translator;

beforeEach(function (): void {
    app()->setLocale('en');
    resolve(Translator::class)->addLines(collect(require __DIR__ . '/../../../resources/lang/en/navigation.php')
        ->mapWithKeys(fn (string $label, string $key): array => ['navigation.' . $key => $label])->all(), 'en', 'capell-admin');
});

it('moves reusable content and design tools into local navigation without losing package additions', function (): void {
    $navigation = new WorkspaceNavigation;
    $items = [];
    foreach ([
        'Media' => '/admin/media',
        'Sections' => '/admin/content-sections/sections',
        'Layouts' => '/admin/layout-builder/layouts',
        'Layout Presets' => '/admin/layout-builder/presets',
        'Widgets' => '/admin/layout-builder/widgets',
        'Block Templates' => '/admin/block-templates',
        'Themes' => '/admin/themes',
        'Package feature' => '/admin/new-package-feature',
    ] as $label => $url) {
        $items[] = NavigationItem::make($label)->url($url)->isActiveWhen(fn (): bool => $label === 'Sections');
    }

    $groups = $navigation->organise([NavigationGroup::make()->items($items)]);
    expect(array_map(fn (NavigationItem $item): string => $item->getLabel(), collect($groups[0]->getItems())->all()))
        ->toBe(['Package feature', 'Content Library', 'Design']);
    $local = $navigation->localNavigation();
    expect(array_map(fn (NavigationItem $item): string => $item->getLabel(), collect($local[0]->getItems())->all()))
        ->toBe(['Media', 'Sections'])
        ->and(collect($groups[0]->getItems())->all()[1]->isActive())->toBeTrue()
        ->and(collect($groups[0]->getItems())->all()[1]->getUrl())->toBe('/admin/media');
});

it('keeps roles reachable inside system and retains original URLs and badges', function (): void {
    $roles = NavigationItem::make('Roles')->url('/admin/shield/roles')->isActiveWhen(fn (): bool => true);
    $users = NavigationItem::make('Users')->url('/admin/users')->badge('4')->childItems([$roles]);
    $navigation = new WorkspaceNavigation;
    $groups = $navigation->organise([NavigationGroup::make()->items([$users])]);
    $local = collect($navigation->localNavigation()[0]->getItems())->all();
    expect(collect($groups[0]->getItems())->all()[0]->getLabel())->toBe('System')
        ->and($local[0]->getBadge())->toBe('4')
        ->and($local[1]->getUrl())->toBe('/admin/shield/roles');
});

it('does not produce empty headings or carry local navigation across a new menu build', function (): void {
    $navigation = new WorkspaceNavigation;
    $navigation->organise([NavigationGroup::make()->items([
        NavigationItem::make('Media')->url('/admin/media')->isActiveWhen(fn (): bool => true),
    ])]);
    expect($navigation->localNavigation())->not->toBeEmpty();
    $groups = $navigation->organise([]);
    expect(collect($groups[0]->getItems())->all())->toBeEmpty()->and($navigation->localNavigation())->toBeEmpty();
});

it('only creates optional workspaces when packages contribute visible destinations', function (): void {
    $navigation = new WorkspaceNavigation;
    $groups = $navigation->organise([
        NavigationGroup::make(__('capell-admin::navigation.group_growth'))->items([
            NavigationItem::make('Campaigns')->url('/admin/campaigns')->isActiveWhen(fn (): bool => true),
        ]),
    ]);
    expect(collect($groups[0]->getItems())->map(fn (NavigationItem $item): string => $item->getLabel())->all())
        ->toBe(['Marketing'])
        ->and(collect($navigation->localNavigation()[0]->getItems())->first()?->getUrl())->toBe('/admin/campaigns');
});
