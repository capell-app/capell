<?php

declare(strict_types=1);

use Capell\Admin\Actions\Bridges\ShouldLoadAdminBridgeAction;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Facades\CapellCore;

it('loads a bridge when admin and package settings are enabled', function (): void {
    $settings = AdminSettings::instance();
    $settings->enable_login_audit_user_bridge = true;
    $settings->save();

    expect(ShouldLoadAdminBridgeAction::run('enable_login_audit_user_bridge', true))->toBeTrue();
});

it('does not load a bridge when the admin setting is disabled', function (): void {
    $settings = AdminSettings::instance();
    $settings->enable_login_audit_user_bridge = false;
    $settings->save();

    expect(ShouldLoadAdminBridgeAction::run('enable_login_audit_user_bridge', true))->toBeFalse();
});

it('does not load a bridge when the package setting is disabled', function (): void {
    $settings = AdminSettings::instance();
    $settings->enable_login_audit_user_bridge = true;
    $settings->save();

    expect(ShouldLoadAdminBridgeAction::run('enable_login_audit_user_bridge', false))->toBeFalse();
});

it('does not load a bridge for unknown admin settings', function (): void {
    expect(ShouldLoadAdminBridgeAction::run('missing_bridge_setting', true))->toBeFalse();
});

it('requires an installed package when a package name is supplied', function (): void {
    $settings = AdminSettings::instance();
    $settings->enable_publishing_studio_user_bridge = true;
    $settings->save();

    CapellCore::registerPackage('capell-app/publishing-studio');
    CapellCore::forcePackageInstalled('capell-app/publishing-studio', false);

    expect(ShouldLoadAdminBridgeAction::run(
        'enable_publishing_studio_user_bridge',
        true,
        'capell-app/publishing-studio',
    ))->toBeFalse();

    CapellCore::forcePackageInstalled('capell-app/publishing-studio');

    expect(ShouldLoadAdminBridgeAction::run(
        'enable_publishing_studio_user_bridge',
        true,
        'capell-app/publishing-studio',
    ))->toBeTrue();
});
