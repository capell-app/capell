<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Tests\Fixtures\Autoload\AbstractPackageSettingsPageMissingClassPage;
use Capell\Admin\Tests\Fixtures\Autoload\AbstractPackageSettingsPageTestPage;
use Capell\Admin\Tests\Fixtures\Autoload\AbstractPackageSettingsPageTestSchema;
use Capell\Admin\Tests\Fixtures\Autoload\AbstractPackageSettingsPageTestSettings;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    CapellCore::clearExtensionCache();
    AbstractPackageSettingsPageTestSettings::$savedValues = [];
    AbstractPackageSettingsPageTestSettings::$persistedDefaultPayloads = [];
    resolve(SettingsSchemaRegistry::class)->removeGroup('abstract-page-test');
    resolve(SettingsSchemaRegistry::class)->removeGroup('abstract-page-missing');
});

function grantPackageSettingsLifecycleAccess(): void
{
    Permission::create(['name' => ExtensionsPage::MANAGE_PERMISSION, 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo(ExtensionsPage::MANAGE_PERMISSION);
}

function registerPackageSettingsMetadata(string $packageName, string $label = 'Test package'): void
{
    resolve(SettingsSchemaRegistry::class)->registerMetadata(new SettingsGroupMetadata(
        group: 'abstract-page-test',
        label: $label,
        packageName: $packageName,
    ));
}

function packageSettingsFormAction(string $name): ?Action
{
    $action = collect((new AbstractPackageSettingsPageTestPage)->getFormActions())
        ->first(fn (object $action): bool => filamentObjectName($action) === $name);

    return $action instanceof Action ? $action : null;
}

it('uses settings group metadata for navigation and page title', function (): void {
    resolve(SettingsSchemaRegistry::class)->registerMetadata(new SettingsGroupMetadata(
        group: 'abstract-page-test',
        label: 'Test package',
        icon: 'heroicon-o-cog',
        navigationGroup: 'Package settings',
        navigationSort: 42,
    ));

    expect(AbstractPackageSettingsPageTestPage::getNavigationLabel())->toBe('Test package')
        ->and(AbstractPackageSettingsPageTestPage::getNavigationGroup())->toBe('Package settings')
        ->and(AbstractPackageSettingsPageTestPage::getNavigationSort())->toBe(42)
        ->and(AbstractPackageSettingsPageTestPage::getNavigationIcon())->toBe('heroicon-o-cog')
        ->and((new AbstractPackageSettingsPageTestPage)->getTitle())->toBe('Test package');
});

it('hydrates registered settings data and aggregates registered schemas', function (): void {
    app()->instance(AbstractPackageSettingsPageTestSettings::class, new AbstractPackageSettingsPageTestSettings([
        'headline' => 'Saved headline',
    ]));

    $registry = resolve(SettingsSchemaRegistry::class);
    $registry->registerSettingsClass('abstract-page-test', AbstractPackageSettingsPageTestSettings::class);
    $registry->register('abstract-page-test', AbstractPackageSettingsPageTestSchema::class);

    $page = new AbstractPackageSettingsPageTestPage;
    $schema = $page->form(Schema::make());

    expect($page->exposeMutateBeforeFill(['headline' => 'Draft headline']))->toBe([
        'headline' => 'Saved headline',
    ])
        ->and($page->exposeSettingsClass())->toBe(AbstractPackageSettingsPageTestSettings::class)
        ->and($schema->getComponents())->toHaveCount(1)
        ->and($schema->getComponents()[0])->toBeInstanceOf(TextInput::class)
        ->and(filamentObjectName($schema->getComponents()[0]))->toBe('headline');
});

it('fails clearly when no settings class is registered for the group', function (): void {
    (new AbstractPackageSettingsPageMissingClassPage)->exposeSettingsClass();
})->throws(RuntimeException::class, 'No settings class registered for settings group [abstract-page-missing].');

it('saves registered package settings through the Filament page workflow', function (): void {
    app()->instance(AbstractPackageSettingsPageTestSettings::class, new AbstractPackageSettingsPageTestSettings([
        'headline' => 'Saved headline',
    ]));

    $registry = resolve(SettingsSchemaRegistry::class);
    $registry->registerSettingsClass('abstract-page-test', AbstractPackageSettingsPageTestSettings::class);
    $registry->register('abstract-page-test', AbstractPackageSettingsPageTestSchema::class);

    Livewire::test(AbstractPackageSettingsPageTestPage::class)
        ->assertSuccessful()
        ->assertSet('data.headline', 'Saved headline')
        ->fillForm([
            'headline' => 'Updated package headline',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(AbstractPackageSettingsPageTestSettings::$savedValues)->toBe([
        'headline' => 'Updated package headline',
    ])
        ->and(AbstractPackageSettingsPageTestSettings::$persistedDefaultPayloads)->toBe([
            'abstract-page-test' => [
                'fallbackHeadline' => 'Fallback package headline',
            ],
        ]);
});

it('adds extension lifecycle actions to the package settings footer for manageable packages', function (): void {
    grantPackageSettingsLifecycleAccess();

    CapellCore::registerPackage(name: 'vendor/settings-extension', version: '1.0.0');
    CapellCore::markPackageInstalled('vendor/settings-extension');
    registerPackageSettingsMetadata('vendor/settings-extension');

    $actionNames = collect((new AbstractPackageSettingsPageTestPage)->getFormActions())
        ->map(fn (object $action): ?string => filamentObjectName($action))
        ->filter()
        ->values()
        ->all();

    expect($actionNames)->toContain('save', 'disableExtension', 'uninstallExtension');
});

it('can disable an extension from the package settings footer', function (): void {
    grantPackageSettingsLifecycleAccess();

    app()->instance(AbstractPackageSettingsPageTestSettings::class, new AbstractPackageSettingsPageTestSettings([
        'headline' => 'Saved headline',
    ]));

    $registry = resolve(SettingsSchemaRegistry::class);
    $registry->registerSettingsClass('abstract-page-test', AbstractPackageSettingsPageTestSettings::class);
    $registry->register('abstract-page-test', AbstractPackageSettingsPageTestSchema::class);
    registerPackageSettingsMetadata('vendor/settings-extension');

    CapellCore::registerPackage(name: 'vendor/settings-extension', version: '1.0.0');
    CapellCore::markPackageInstalled('vendor/settings-extension');

    $disableAction = collect((new AbstractPackageSettingsPageTestPage)->getFormActions())
        ->first(fn (object $action): bool => filamentObjectName($action) === 'disableExtension');
    $disableAction = expectPresent($disableAction);

    expect($disableAction)->not->toBeNull();

    filamentObjectMethod($disableAction, 'call');

    $extension = CapellExtension::query()
        ->where('composer_name', 'vendor/settings-extension')
        ->first();

    expect($extension?->status)->toBe(ExtensionStatusEnum::Disabled)
        ->and(CapellCore::isPackageEnabled('vendor/settings-extension'))->toBeFalse();
});

it('can uninstall an extension from the package settings footer', function (): void {
    grantPackageSettingsLifecycleAccess();

    CapellCore::registerPackage(name: 'vendor/settings-extension', version: '1.0.0');
    CapellCore::markPackageInstalled('vendor/settings-extension');
    registerPackageSettingsMetadata('vendor/settings-extension');

    $uninstallAction = expectPresent(packageSettingsFormAction('uninstallExtension'));

    expect($uninstallAction)->not->toBeNull()
        ->and($uninstallAction->isVisible())->toBeTrue();

    $uninstallAction->call();

    expect(CapellExtension::query()->where('composer_name', 'vendor/settings-extension')->exists())->toBeFalse()
        ->and(CapellCore::isPackageInstalled('vendor/settings-extension'))->toBeFalse();
});

it('keeps dependency-blocked package settings uninstall visible but close-only', function (): void {
    grantPackageSettingsLifecycleAccess();

    CapellCore::registerPackage(name: 'vendor/settings-base', version: '1.0.0');
    CapellCore::markPackageInstalled('vendor/settings-base');
    CapellCore::registerPackage(name: 'vendor/settings-dependent', version: '1.0.0');
    CapellCore::getPackage('vendor/settings-dependent')->requirements = ['vendor/settings-base'];
    CapellCore::markPackageInstalled('vendor/settings-dependent');
    registerPackageSettingsMetadata('vendor/settings-base');

    $uninstallAction = expectPresent(packageSettingsFormAction('uninstallExtension'));

    expect($uninstallAction)->not->toBeNull()
        ->and($uninstallAction->isVisible())->toBeTrue()
        ->and($uninstallAction->getModalSubmitAction())->toBeNull()
        ->and($uninstallAction->getModalCancelActionLabel())->toBe(__('capell-admin::button.close'))
        ->and($uninstallAction->getTooltip())->toBe(trans_choice('capell-admin::generic.extension_uninstall_blocked_by_dependents', 1, [
            'extensions' => 'Settings Dependent (vendor/settings-dependent)',
        ]))
        ->and($uninstallAction->getModalDescription())->toBe(trans_choice('capell-admin::generic.extension_uninstall_blocked_modal_dependents', 1, [
            'extensions' => 'Settings Dependent (vendor/settings-dependent)',
        ]));

    $uninstallAction->call();

    expect(CapellCore::isPackageInstalled('vendor/settings-base'))->toBeTrue();
});

it('shows trusted package settings uninstall when no dependents block it', function (): void {
    grantPackageSettingsLifecycleAccess();

    CapellCore::registerPackage(
        name: 'capell-app/frontend',
        path: realpath(__DIR__ . '/../../../../../frontend') ?: null,
        version: '1.0.0',
    );
    CapellCore::markPackageInstalled('capell-app/frontend');
    registerPackageSettingsMetadata('capell-app/frontend');

    $uninstallAction = expectPresent(packageSettingsFormAction('uninstallExtension'));
    $disableAction = expectPresent(packageSettingsFormAction('disableExtension'));

    expect($uninstallAction)->not->toBeNull()
        ->and($uninstallAction->isVisible())->toBeTrue()
        ->and($uninstallAction->getModalDescription())->toBe(__('capell-admin::generic.uninstall_extension_description'))
        ->and($disableAction->isVisible())->toBeFalse();

    $uninstallAction->call();

    expect(CapellCore::isPackageInstalled('capell-app/frontend'))->toBeFalse();
});
