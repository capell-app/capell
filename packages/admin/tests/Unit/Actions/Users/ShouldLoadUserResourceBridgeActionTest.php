<?php

declare(strict_types=1);

use Capell\Admin\Actions\Users\ShouldLoadUserResourceBridgeAction;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Facades\CapellCore;

it('loads a user resource bridge when admin and package settings are enabled', function (): void {
    $settings = AdminSettings::instance();
    $settings->enable_login_audit_user_bridge = true;
    $settings->save();

    expect(ShouldLoadUserResourceBridgeAction::run('enable_login_audit_user_bridge', true))->toBeTrue();
});

it('loads a category bridge when admin and package settings are enabled', function (string $adminSetting): void {
    $settings = AdminSettings::instance();

    match ($adminSetting) {
        'enable_security_access_user_bridge' => $settings->enable_security_access_user_bridge = true,
        'enable_content_ownership_user_bridge' => $settings->enable_content_ownership_user_bridge = true,
        'enable_support_actions_user_bridge' => $settings->enable_support_actions_user_bridge = true,
        default => throw new InvalidArgumentException('Unsupported admin setting.'),
    };

    $settings->save();

    expect(ShouldLoadUserResourceBridgeAction::run($adminSetting, true))->toBeTrue();
})->with([
    'security access' => 'enable_security_access_user_bridge',
    'content ownership' => 'enable_content_ownership_user_bridge',
    'support actions' => 'enable_support_actions_user_bridge',
]);

it('does not load a user resource bridge when the admin setting is disabled', function (): void {
    $settings = AdminSettings::instance();
    $settings->enable_login_audit_user_bridge = false;
    $settings->save();

    expect(ShouldLoadUserResourceBridgeAction::run('enable_login_audit_user_bridge', true))->toBeFalse();
});

it('does not load a user resource bridge when the package setting is disabled', function (): void {
    $settings = AdminSettings::instance();
    $settings->enable_login_audit_user_bridge = true;
    $settings->save();

    expect(ShouldLoadUserResourceBridgeAction::run('enable_login_audit_user_bridge', false))->toBeFalse();
});

it('does not load a user resource bridge for unknown admin settings', function (): void {
    expect(ShouldLoadUserResourceBridgeAction::run('missing_user_bridge_setting', true))->toBeFalse();
});

it('does not load a user resource bridge when the package is registered but not installed', function (): void {
    $settings = AdminSettings::instance();
    $settings->enable_publishing_studio_user_bridge = true;
    $settings->save();

    CapellCore::registerPackage('capell-app/publishing-studio');
    CapellCore::forcePackageInstalled('capell-app/publishing-studio', false);

    expect(ShouldLoadUserResourceBridgeAction::run(
        'enable_publishing_studio_user_bridge',
        true,
        'capell-app/publishing-studio',
    ))->toBeFalse();
});

it('loads a user resource bridge when the package is installed', function (): void {
    $settings = AdminSettings::instance();
    $settings->enable_publishing_studio_user_bridge = true;
    $settings->save();

    CapellCore::registerPackage('capell-app/publishing-studio');
    CapellCore::forcePackageInstalled('capell-app/publishing-studio');

    expect(ShouldLoadUserResourceBridgeAction::run(
        'enable_publishing_studio_user_bridge',
        true,
        'capell-app/publishing-studio',
    ))->toBeTrue();
});
