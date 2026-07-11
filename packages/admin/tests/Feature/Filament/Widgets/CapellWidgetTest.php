<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Filament\Widgets;

use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Tests\Feature\Filament\Widgets\Fixtures\DeveloperOnlyFixtureFilamentWidget;
use Capell\Admin\Tests\Feature\Filament\Widgets\Fixtures\UngatedFixtureFilamentWidget;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Spatie\Permission\Models\Role;

uses(CreatesAdminUser::class)
    ->group('widget');

beforeEach(function (): void {
    Role::findOrCreate('super_admin');
    Role::findOrCreate('editor');
});

it('hides a role-gated widget from users without the role', function (): void {
    $user = test()->createUser();
    $user->assignRole('editor');
    $this->actingAs($user);

    expect(DeveloperOnlyFixtureFilamentWidget::canView())->toBeFalse();
});

it('shows a role-gated widget to users with the configured role', function (): void {
    $user = test()->createUser();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    expect(DeveloperOnlyFixtureFilamentWidget::canView())->toBeTrue();
});

it('respects a remapped super_admin role name from config', function (): void {
    config()->set('capell.roles.super_admin', 'platform-admin');
    Role::findOrCreate('platform-admin');

    $user = test()->createUser();
    $user->assignRole('platform-admin');
    $this->actingAs($user);

    expect(DeveloperOnlyFixtureFilamentWidget::canView())->toBeTrue();
});

it('hides the widget when the settings toggle is off', function (): void {
    $user = test()->createUser();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    $settings = resolve(AdminSettings::class);
    $settings->enabled_widgets = ['fixture_widget' => false];
    $settings->save();

    expect(DeveloperOnlyFixtureFilamentWidget::canView())->toBeFalse();
});

it('shows an ungated widget to any authenticated user', function (): void {
    $user = test()->createUser();
    $this->actingAs($user);

    expect(UngatedFixtureFilamentWidget::canView())->toBeTrue();
});

it('hides an ungated widget from guests', function (): void {
    expect(UngatedFixtureFilamentWidget::canView())->toBeFalse();
});

it('allows super_admin to view a widget gated to super_admin role', function (): void {
    Role::findOrCreate('super_admin');

    $superAdminUser = test()->createUserWithRole('super_admin');
    $this->actingAs($superAdminUser);

    expect(DeveloperOnlyFixtureFilamentWidget::canView())->toBeTrue();
});
