<?php

declare(strict_types=1);

use Capell\Admin\Enums\NavigationGroupEnum;
use Capell\Admin\Enums\NavigationGroupPositionEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Support\CapellAdminManager;
use Capell\Admin\Support\Navigation\NavigationGroupResolver;
use Filament\Navigation\NavigationGroup;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    CapellAdmin::clearResolvedInstance(CapellAdminManager::class);
});

it('offers the fixed groups in order, keeping website and media open', function (): void {
    $groups = collect(CapellAdmin::getNavigationGroups());

    expect($groups->map(fn (NavigationGroup $group): ?string => $group->getLabel())->all())
        ->toBe(['Website', 'Media', 'Design', 'Marketing', 'Business', 'Reports', 'Users', 'Settings', 'Developer'])
        ->and($groups->map(fn (NavigationGroup $group): bool => $group->isCollapsed())->all())
        ->toBe([false, false, true, true, true, true, true, true, true])
        ->and($groups->every(fn (NavigationGroup $group): bool => $group->getIcon() === null))->toBeTrue();
});

it('keeps host supplied groups when the panel sets its own', function (): void {
    $custom = [NavigationGroup::make('Host group')];

    expect(CapellAdmin::setNavigationGroups($custom)->getNavigationGroups())->toBe($custom);
});

it('no longer adds a group when a package registers one', function (): void {
    $log = Log::spy();

    CapellAdmin::registerNavigationGroup(
        label: 'Bookings',
        position: NavigationGroupPositionEnum::After,
        relativeTo: 'capell-admin::navigation.group_marketing',
    );

    expect(collect(CapellAdmin::getNavigationGroups())->map(fn (NavigationGroup $group): ?string => $group->getLabel())->all())
        ->not->toContain('Bookings');
    $log->shouldHaveReceived('debug')->withArgs(fn (string $message): bool => str_contains($message, 'registerNavigationGroup'))->once();
});

it('records navigation item replacements', function (): void {
    CapellAdmin::replaceNavigationItem('core.redirects', 'package.redirects');

    expect(CapellAdmin::getNavigationReplacements())->toBe(['core.redirects' => 'package.redirects']);
});

it('resolves cases, values, keys and labels to a fixed group', function (string|NavigationGroupEnum|null $group, ?NavigationGroupEnum $expected): void {
    expect(resolve(NavigationGroupResolver::class)->resolve($group))->toBe($expected);
})->with([
    'enum case' => [NavigationGroupEnum::Business, NavigationGroupEnum::Business],
    'case value' => ['marketing', NavigationGroupEnum::Marketing],
    'case translation key' => ['capell-admin::navigation.group_users', NavigationGroupEnum::Users],
    'case label' => ['Reports', NavigationGroupEnum::Reports],
    'unknown package group' => ['Bookings', NavigationGroupEnum::Settings],
    'retired group label' => ['Customers', NavigationGroupEnum::Settings],
    'retired monitoring label' => ['Insights & health', NavigationGroupEnum::Settings],
    'dashboard' => ['capell-admin::navigation.group_dashboard', null],
    'no group' => [null, null],
]);

it('logs an unknown group label once', function (): void {
    $log = Log::spy();
    $resolver = new NavigationGroupResolver;

    $resolver->resolve('System');
    $resolver->resolve('System');
    $resolver->resolve(NavigationGroupEnum::Settings);

    $log->shouldHaveReceived('debug')->withArgs(fn (string $message, array $context): bool => $context === ['group' => 'System'])->once();
});
