<?php

declare(strict_types=1);

use Capell\Admin\Actions\AssignPermissionsToRole;
use Capell\Admin\Actions\EnsureCapellPermissionsAction;
use Capell\Admin\Actions\GrantCapellDefaultRolePermissionsAction;
use Capell\Admin\Actions\SeedDefaultRolesAction;
use Capell\Admin\Enums\PageEnum;
use Capell\Admin\Enums\PermissionSyncMode;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Users\UserResource;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Navigation\NavigationManager;
use Illuminate\Support\Collection;

beforeEach(function (): void {
    EnsureCapellPermissionsAction::run();
    AssignPermissionsToRole::run(resources: ResourceEnum::cases(), pages: PageEnum::cases());
    GrantCapellDefaultRolePermissionsAction::run(PermissionSyncMode::Install);
    SeedDefaultRolesAction::run();
});

it('shows editors a concise task navigation', function (): void {
    test()->actingAsRole('editor');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Filament::setServingStatus();
    $groups = collect(Filament::getNavigation());
    $items = $groups->flatMap(fn (NavigationGroup $group): Collection => collect($group->getItems()));
    $allItems = flattenTaskNavigation($groups->all());

    expect($groups->map(fn (NavigationGroup $group): ?string => $group->getLabel())->all())->toContain('Content', 'Media')
        ->and(count($allItems))->toBeLessThanOrEqual(10)
        ->and($items->map(fn (NavigationItem $item): ?string => $item->getUrl())->all())->toContain(PageResource::getUrl())
        ->not->toContain(UserResource::getUrl());
});

it('keeps administrator destinations reachable', function (): void {
    test()->actingAsAdmin();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Filament::setServingStatus();
    $items = collect(Filament::getNavigation())->flatMap(fn (NavigationGroup $group): Collection => collect($group->getItems()));
    expect($items->map(fn (NavigationItem $item): ?string => $item->getUrl())->all())
        ->toContain(PageResource::getUrl(), UserResource::getUrl());

    $registered = new ReflectionMethod(NavigationManager::class, 'get')->invoke(resolve(NavigationManager::class));
    $expected = collect(flattenTaskNavigation($registered))
        ->filter(fn (NavigationItem $item): bool => str_starts_with($item->getKey(), 'Capell\\Admin\\Filament\\'))
        ->map(fn (NavigationItem $item): string => $item->getKey())->all();
    $actual = collect(flattenTaskNavigation(Filament::getNavigation()))
        ->map(fn (NavigationItem $item): string => $item->getKey())->all();
    expect($expected)->not->toBeEmpty();
    foreach ($expected as $key) {
        expect($actual)->toContain($key);
    }
});

/** @param array<NavigationGroup> $groups
 * @return list<NavigationItem>
 */
function flattenTaskNavigation(array $groups): array
{
    $items = [];
    $add = function (NavigationItem $item) use (&$add, &$items): void {
        $items[] = $item;
        foreach (collect($item->getChildItems()) as $child) {
            if ($child->isVisible()) {
                $add($child);
            }
        }
    };
    foreach ($groups as $group) {
        foreach (collect($group->getItems()) as $item) {
            $add($item);
        }
    }

    return $items;
}
