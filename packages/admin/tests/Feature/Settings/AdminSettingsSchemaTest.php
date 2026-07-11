<?php

declare(strict_types=1);

use Capell\Admin\Filament\Contracts\HasSchema;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Filament\Settings\AdminSettingsSchema;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)
    ->group('admin', 'settings');

test('registers admin settings schema in registry', function (): void {
    $registry = resolve(SettingsSchemaRegistry::class);

    expect($registry->hasGroup('admin'))->toBeTrue()
        ->and($registry->getSettingsClass('admin'))->toBe(AdminSettings::class)
        ->and($registry->getSchemas('admin'))->toHaveKey('AdminSettingsSchema');
});

test('admin settings schema implements hasschema contract', function (): void {
    $interfaces = class_implements(AdminSettingsSchema::class);

    expect($interfaces)->toContain(HasSchema::class);
});

test('admin settings schema returns form components', function (): void {
    $schema = Mockery::mock(Schema::class);
    $components = AdminSettingsSchema::make($schema);

    expect($components)->toBeArray();
});

test('admin settings fields are grouped inside contained sections', function (): void {
    $schema = Mockery::mock(Schema::class);
    $components = AdminSettingsSchema::make($schema);

    expect($components)
        ->toHaveCount(3)
        ->each->toBeInstanceOf(Section::class);

    foreach ($components as $component) {
        expect($component->isContained())->toBeTrue();
    }

    expect(array_map(function (Section $section): string {
        $description = $section->getDescription();

        return $description instanceof Htmlable ? $description->toHtml() : (string) $description;
    }, $components))
        ->toBe([
            __('capell-admin::generic.admin_settings_description'),
            __('capell-admin::generic.admin_interface_description'),
            __('capell-admin::generic.user_resource_bridges_description'),
        ]);
});

test('admin settings schema exposes user resource bridge controls', function (): void {
    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage');

    Livewire::test(SettingsPage::class)
        ->assertSuccessful()
        ->assertFormFieldExists('admin.enable_login_audit_user_bridge')
        ->assertFormFieldExists('admin.enable_publishing_studio_user_bridge')
        ->assertFormFieldExists('admin.enable_agent_bridge_user_bridge')
        ->assertFormFieldExists('admin.enable_security_access_user_bridge')
        ->assertFormFieldExists('admin.enable_content_ownership_user_bridge')
        ->assertFormFieldExists('admin.enable_support_actions_user_bridge');
});

test('admin settings schema exposes form action position control', function (): void {
    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage');

    Livewire::test(SettingsPage::class)
        ->assertSuccessful()
        ->assertFormFieldExists('admin.form_action_position');
});

test('admin settings schema exposes header navigation tree control', function (): void {
    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage');

    Livewire::test(SettingsPage::class)
        ->assertSuccessful()
        ->assertFormFieldExists('admin.enable_header_navigation_tree');
});

test('admin settings schema form action position control has translated labels and helper text', function (): void {
    $translationKeys = [
        'form_action_position',
        'form_action_position_helper',
        'admin_form_action_position_above_form',
        'admin_form_action_position_below_form',
    ];

    foreach ($translationKeys as $translationKey) {
        expect(__('capell-admin::form.' . $translationKey))->not->toBe('capell-admin::form.' . $translationKey);
    }
});

test('admin settings schema header navigation tree control has translated labels and helper text', function (): void {
    foreach (['enable_header_navigation_tree', 'enable_header_navigation_tree_helper'] as $translationKey) {
        expect(__('capell-admin::form.' . $translationKey))->not->toBe('capell-admin::form.' . $translationKey);
    }
});

test('admin settings schema bridge controls have translated labels and helper text', function (): void {
    $translationKeys = [
        'enable_login_audit_user_bridge',
        'enable_publishing_studio_user_bridge',
        'enable_agent_bridge_user_bridge',
        'enable_security_access_user_bridge',
        'enable_content_ownership_user_bridge',
        'enable_support_actions_user_bridge',
    ];

    foreach ($translationKeys as $translationKey) {
        expect(__('capell-admin::form.' . $translationKey))->not->toBe('capell-admin::form.' . $translationKey)
            ->and(__('capell-admin::form.' . $translationKey . '_helper'))->not->toBe('capell-admin::form.' . $translationKey . '_helper');
    }
});
