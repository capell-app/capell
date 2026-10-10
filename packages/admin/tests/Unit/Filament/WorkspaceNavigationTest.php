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

it('keeps content grouped and secondary design tools local without losing package additions', function (): void {
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
        $items[] = NavigationItem::make($label)->url($url)->isActiveWhen(fn (): bool => $label === 'Widgets');
    }

    $groups = $navigation->organise([NavigationGroup::make()->items($items)]);
    expect(array_map(fn (NavigationGroup $group): ?string => $group->getLabel(), $groups))
        ->toBe([null, 'Content Library', 'Design']);
    expect(collect($groups[0]->getItems())->first()?->getLabel())->toBe('Package feature')
        ->and(collect($groups[1]->getItems())->map(fn (NavigationItem $item): string => $item->getLabel())->all())->toBe(['Media', 'Sections'])
        ->and(collect($groups[2]->getItems())->map(fn (NavigationItem $item): string => $item->getLabel())->all())->toBe(['Layouts', 'Themes'])
        ->and(collect($groups[2]->getItems())->first()?->isActive())->toBeTrue();
    $local = $navigation->localNavigation();
    expect(collect($local[0]->getItems())->map(fn (NavigationItem $item): string => $item->getLabel())->all())
        ->toBe(['Layouts', 'Layout Presets', 'Widgets', 'Block Templates', 'Themes']);
});

it('keeps roles reachable inside system and retains original URLs and badges', function (): void {
    $roles = NavigationItem::make('Roles')->url('/admin/shield/roles')->isActiveWhen(fn (): bool => true);
    $users = NavigationItem::make('Users')->url('/admin/users')->badge('4')->childItems([$roles]);
    $navigation = new WorkspaceNavigation;
    $groups = $navigation->organise([NavigationGroup::make()->items([$users])]);
    $item = collect($groups[0]->getItems())->first();
    expect($groups[0]->getLabel())->toBe('System')
        ->and($item?->getBadge())->toBe('4')
        ->and(collect($item?->getChildItems())->first()?->getUrl())->toBe('/admin/shield/roles')
        ->and($navigation->localNavigation())->toBeEmpty();
});

it('does not produce empty headings or carry local navigation across a new menu build', function (): void {
    $navigation = new WorkspaceNavigation;
    $navigation->organise([NavigationGroup::make()->items([
        NavigationItem::make('Layouts')->url('/admin/layout-builder/layouts')->isActiveWhen(fn (): bool => true),
    ])]);
    expect($navigation->localNavigation())->not->toBeEmpty();
    $groups = $navigation->organise([]);
    expect($groups)->toBeEmpty()->and($navigation->localNavigation())->toBeEmpty();
});

it('only creates optional workspaces when packages contribute visible destinations', function (): void {
    $navigation = new WorkspaceNavigation;
    $groups = $navigation->organise([
        NavigationGroup::make(__('capell-admin::navigation.group_growth'))->items([
            NavigationItem::make('Campaigns')->url('/admin/campaigns')->isActiveWhen(fn (): bool => true),
        ]),
    ]);
    expect($groups[0]->getLabel())->toBe('Marketing')
        ->and(collect($groups[0]->getItems())->first()?->getUrl())->toBe('/admin/campaigns')
        ->and($navigation->localNavigation())->toBeEmpty();
});
