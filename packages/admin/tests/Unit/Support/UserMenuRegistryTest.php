<?php

declare(strict_types=1);

use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Tests\Fixtures\Autoload\UserMenuRegistryTestUser;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;

beforeEach(function (): void {
    CapellAdmin::clearUserMenuItems();
});

it('registers and resolves visible user menu items in sort order', function (): void {
    $user = new UserMenuRegistryTestUser(1, 'Ben');

    CapellAdmin::registerUserMenuItem(
        key: 'capell-test.second',
        label: 'Second item',
        icon: Heroicon::OutlinedBell,
        url: fn (Authenticatable $user): string => '/admin/second/' . $user->getAuthIdentifier(),
        badge: fn (): int => 3,
        badgeColor: fn (): string => 'warning',
        visible: fn (): bool => true,
        sort: 20,
    );

    CapellAdmin::registerUserMenuItem(
        key: 'capell-test.first',
        label: 'First item',
        icon: Heroicon::OutlinedInbox,
        url: '/admin/first',
        visible: true,
        sort: 10,
    );

    $items = CapellAdmin::getUserMenuItems($user);

    expect(array_keys($items))->toBe(['capell-test.first', 'capell-test.second'])
        ->and($items['capell-test.first'])->toBeInstanceOf(Action::class)
        ->and($items['capell-test.first']->getName())->toBe('capell-test.first')
        ->and($items['capell-test.first']->getLabel())->toBe('First item')
        ->and($items['capell-test.first']->getUrl())->toBe('/admin/first')
        ->and($items['capell-test.first']->getIcon())->toBe(Heroicon::OutlinedInbox)
        ->and($items['capell-test.second'])->toBeInstanceOf(Action::class)
        ->and($items['capell-test.second']->getUrl())->toBe('/admin/second/1')
        ->and($items['capell-test.second']->getBadge())->toBe('3')
        ->and($items['capell-test.second']->getBadgeColor())->toBe('warning');
});

it('omits invisible items and hides empty badges', function (): void {
    $user = new UserMenuRegistryTestUser(2, 'Sarah');

    CapellAdmin::registerUserMenuItem(
        key: 'capell-test.hidden',
        label: 'Hidden',
        url: '/admin/hidden',
        visible: false,
    );

    CapellAdmin::registerUserMenuItem(
        key: 'capell-test.zero',
        label: 'Zero',
        url: '/admin/zero',
        badge: fn (): int => 0,
        visible: true,
    );

    $items = CapellAdmin::getUserMenuItems($user);

    expect($items)->toHaveKey('capell-test.zero')
        ->and($items)->not->toHaveKey('capell-test.hidden')
        ->and($items['capell-test.zero']->getBadge())->toBeNull();
});

it('normalizes large badges and isolates failing callbacks', function (): void {
    $user = new UserMenuRegistryTestUser(3, 'Jim');

    CapellAdmin::registerUserMenuItem(
        key: 'capell-test.large',
        label: 'Large',
        url: '/admin/large',
        badge: fn (): int => 123,
        visible: true,
    );

    CapellAdmin::registerUserMenuItem(
        key: 'capell-test.broken',
        label: 'Broken',
        url: fn (): string => throw new RuntimeException('Broken callback'),
        visible: true,
    );

    $items = CapellAdmin::getUserMenuItems($user);

    expect($items)->toHaveKey('capell-test.large')
        ->and($items)->not->toHaveKey('capell-test.broken')
        ->and($items['capell-test.large']->getBadge())->toBe('99+')
        ->and($items['capell-test.large']->getBadgeColor())->toBe('primary');
});

it('caches resolved user menu callbacks per user', function (): void {
    $user = new UserMenuRegistryTestUser(5, 'Cache');
    $badgeCalls = 0;

    CapellAdmin::registerUserMenuItem(
        key: 'capell-test.cached',
        label: 'Cached',
        url: '/admin/cached',
        badge: function () use (&$badgeCalls): int {
            $badgeCalls++;

            return 7;
        },
    );

    CapellAdmin::getUserMenuItems($user);
    CapellAdmin::getUserMenuItems($user);

    expect($badgeCalls)->toBe(1);
});

it('skips all items without a user and omits blank urls', function (): void {
    $user = new UserMenuRegistryTestUser(4, 'Ada');

    CapellAdmin::registerUserMenuItem(
        key: 'capell-test.blank',
        label: 'Blank',
        url: '',
        visible: true,
    );

    CapellAdmin::registerUserMenuItem(
        key: 'capell-test.ready',
        label: 'Ready',
        url: '/admin/ready',
        visible: true,
    );

    expect(CapellAdmin::getUserMenuItems())->toBe([]);

    $items = CapellAdmin::getUserMenuItems($user);

    expect($items)->toHaveKey('capell-test.ready')
        ->and($items)->not->toHaveKey('capell-test.blank');
});
