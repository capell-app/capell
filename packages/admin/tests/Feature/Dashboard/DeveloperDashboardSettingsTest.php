<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Dashboard;

use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Tests\Feature\Dashboard\Fixtures\SettingsGatedWidgetAlpha;
use Capell\Admin\Tests\Feature\Dashboard\Fixtures\SettingsGatedWidgetBeta;
use Capell\Admin\Tests\Feature\Dashboard\Fixtures\SettingsGatedWidgetGamma;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Spatie\Permission\Models\Role;

uses(CreatesAdminUser::class)
    ->group('dashboard');

beforeEach(function (): void {
    Role::findOrCreate('super_admin');
    Role::findOrCreate('editor');
});

it('shows widget when enabled in settings', function (): void {
    $user = test()->createUser();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    $settings = resolve(AdminSettings::class);
    $settings->enabled_widgets = ['widget_alpha' => true];
    $settings->save();

    expect(SettingsGatedWidgetAlpha::canView())->toBeTrue();
});

it('hides widget when disabled in settings', function (): void {
    $user = test()->createUser();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    $settings = resolve(AdminSettings::class);
    $settings->enabled_widgets = ['widget_alpha' => false];
    $settings->save();

    expect(SettingsGatedWidgetAlpha::canView())->toBeFalse();
});

it('defaults to enabled when widget not in settings', function (): void {
    $user = test()->createUser();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    $settings = resolve(AdminSettings::class);
    $settings->enabled_widgets = [];
    $settings->save();

    expect(SettingsGatedWidgetAlpha::canView())->toBeTrue();
});

it('toggles widgets independently', function (): void {
    $user = test()->createUser();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    $settings = resolve(AdminSettings::class);
    $settings->enabled_widgets = [
        'widget_alpha' => true,
        'widget_beta' => false,
        'widget_gamma' => true,
    ];
    $settings->save();

    expect(SettingsGatedWidgetAlpha::canView())->toBeTrue();
    expect(SettingsGatedWidgetBeta::canView())->toBeFalse();
    expect(SettingsGatedWidgetGamma::canView())->toBeTrue();
});

it('persists settings across refresh', function (): void {
    $user = test()->createUser();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    $settings = resolve(AdminSettings::class);
    $settings->enabled_widgets = ['widget_alpha' => false];
    $settings->save();

    $fresh = AdminSettings::instance()->refresh();
    expect($fresh->isWidgetEnabled('widget_alpha'))->toBeFalse();
    expect(SettingsGatedWidgetAlpha::canView())->toBeFalse();
});

it('respects mixed enabled and disabled widgets across multiple saves', function (): void {
    $user = test()->createUser();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    $settings = resolve(AdminSettings::class);
    $settings->enabled_widgets = ['widget_alpha' => true, 'widget_beta' => true];
    $settings->save();

    expect(SettingsGatedWidgetAlpha::canView())->toBeTrue();
    expect(SettingsGatedWidgetBeta::canView())->toBeTrue();

    $settings->enabled_widgets = ['widget_alpha' => false, 'widget_beta' => true];
    $settings->save();

    expect(SettingsGatedWidgetAlpha::canView())->toBeFalse();
    expect(SettingsGatedWidgetBeta::canView())->toBeTrue();
});
