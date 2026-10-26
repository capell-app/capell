<?php

declare(strict_types=1);

use Capell\Admin\Actions\Users\ResolveAdminLocaleForUserAction;
use Capell\Admin\Enums\AdminFormActionPositionEnum;
use Capell\Admin\Enums\EditorEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Enums\ImageSourceType;
use Capell\Core\Settings\CoreSettings;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)->group('settings');

/** @return list<string> */
function settingsPageFormActionNames(SettingsPage $page): array
{
    $names = collect($page->getFormActions())
        ->map(fn (object $action): ?string => filamentObjectName($action))
        ->filter()
        ->values()
        ->all();

    return array_values($names);
}

/** @return list<string> */
function settingsPageHeaderActionNames(SettingsPage $page): array
{
    $getHeaderActions = new ReflectionMethod($page, 'getHeaderActions');
    $headerActions = $getHeaderActions->invoke($page);

    assert(is_array($headerActions));

    $names = collect($headerActions)
        ->map(fn (object $action): ?string => filamentObjectName($action))
        ->filter()
        ->values()
        ->all();

    return array_values($names);
}

it('saves core settings via the settings page', function (): void {
    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage');

    expect(resolve(CoreSettings::class)->default_locale)->toBe('en');

    Livewire::test(SettingsPage::class)
        ->assertSuccessful()
        ->assertSchemaComponentStateSet('core.default_locale', 'en')
        ->assertSchemaComponentStateSet('core.allowed_image_sources', 'all')
        ->assertSchemaComponentStateSet('core.default_image_source', ImageSourceType::Media->value)
        ->fillForm([
            'core' => [
                'default_locale' => 'fr',
                'allowed_image_sources' => 'upload_media',
                'default_image_source' => ImageSourceType::Upload->value,
                'allowed_remote_image_domains' => ['cdn.example.com'],
                'allow_relative_image_urls' => false,
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(CoreSettings::class);

    $settings = resolve(CoreSettings::class);

    expect($settings->default_locale)->toBe('fr')
        ->and($settings->allowed_image_sources)->toBe('upload_media')
        ->and($settings->default_image_source)->toBe(ImageSourceType::Upload->value)
        ->and($settings->allowed_remote_image_domains)->toBe(['cdn.example.com'])
        ->and($settings->allow_relative_image_urls)->toBeFalse();

    expect(decodedSettingPayload('core', 'default_locale'))->toBe('fr')
        ->and(decodedSettingPayload('core', 'allowed_image_sources'))->toBe('upload_media');
});

it('uses form actions below the settings form by default', function (): void {
    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage');

    $page = Livewire::test(SettingsPage::class)
        ->assertSuccessful()
        ->instance();

    expect(settingsPageFormActionNames($page))->toBe(['save'])
        ->and(settingsPageHeaderActionNames($page))->toBe([]);
});

it('moves settings form actions above the form when configured', function (): void {
    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage');

    $settings = AdminSettings::instance();
    $settings->form_action_position = AdminFormActionPositionEnum::AboveForm;
    $settings->save();

    $page = Livewire::test(SettingsPage::class)
        ->assertSuccessful()
        ->instance();

    expect(settingsPageFormActionNames($page))->toBe([])
        ->and(settingsPageHeaderActionNames($page))->toBe(['save']);
});

it('saves html_editor setting via the settings page admin tab', function (): void {
    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage');

    expect(CapellAdmin::settings()->html_editor)->toBe(EditorEnum::RichEditor);

    Livewire::test(SettingsPage::class)
        ->assertSuccessful()
        ->assertSchemaComponentStateSet('admin.html_editor', EditorEnum::RichEditor)
        ->fillForm([
            'admin' => ['html_editor' => EditorEnum::TinyMCE->value],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertDispatched('refresh-sidebar');

    expect(CapellAdmin::settings()->html_editor)->toBe(EditorEnum::TinyMCE);

    expect(decodedSettingPayload('admin', 'html_editor'))->toBe(EditorEnum::TinyMCE->value);
});

it('saves form action position setting via the settings page admin tab', function (): void {
    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage');

    expect(CapellAdmin::settings()->form_action_position)->toBe(AdminFormActionPositionEnum::BelowForm);

    Livewire::test(SettingsPage::class)
        ->assertSuccessful()
        ->assertSchemaComponentStateSet('admin.form_action_position', AdminFormActionPositionEnum::BelowForm)
        ->fillForm([
            'admin' => ['form_action_position' => AdminFormActionPositionEnum::AboveForm->value],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertDispatched('refresh-sidebar');

    expect(CapellAdmin::settings()->form_action_position)->toBe(AdminFormActionPositionEnum::AboveForm);

    expect(decodedSettingPayload('admin', 'form_action_position'))->toBe(AdminFormActionPositionEnum::AboveForm->value);
});

it('persists missing default admin settings before saving the settings page admin tab', function (): void {
    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage');

    DB::table('settings')
        ->where('group', 'admin')
        ->where('name', 'widget_order')
        ->delete();

    app()->forgetInstance(AdminSettings::class);

    Livewire::test(SettingsPage::class)
        ->assertSuccessful()
        ->fillForm([
            'admin' => ['html_editor' => EditorEnum::TinyMCE->value],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas('settings', [
        'group' => 'admin',
        'name' => 'widget_order',
    ]);

    expect(decodedSettingPayload('admin', 'html_editor'))->toBe(EditorEnum::TinyMCE->value);
});

it('persists dashboard Filament widget layout across settings page admin tab saves', function (): void {
    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage');

    $settings = AdminSettings::instance();
    $settings->widget_order = [
        'site_stats_overview' => 1,
        'my_work_queue' => 2,
        'recently_published' => 3,
    ];
    $settings->save();

    Livewire::test(SettingsPage::class)
        ->assertSuccessful()
        ->fillForm([
            'admin' => ['widget_layout' => [
                [
                    'key' => 'my_work_queue',
                    'label' => 'My work queue',
                    'group' => 'Editor',
                    'enabled' => true,
                    'order' => 5,
                ],
            ]],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $freshSettings = AdminSettings::instance()->refresh();

    expect($freshSettings->widget_order)->toEqual(array_replace(AdminSettings::defaultWidgetOrder(), [
        'site_stats_overview' => 1,
        'my_work_queue' => 5,
        'recently_published' => 3,
    ]))
        ->and($freshSettings->enabled_widgets)->toMatchArray([
            'my_work_queue' => true,
            'site_stats_overview' => false,
        ]);
});

it('rejects an image default excluded by the selected policy', function (string $policy, string $source): void {
    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage');

    Livewire::test(SettingsPage::class)
        ->fillForm(['core' => ['allowed_image_sources' => $policy, 'default_image_source' => $source]])
        ->call('save')
        ->assertHasFormErrors(['core.default_image_source']);

    expect(resolve(CoreSettings::class)->refresh()->allowed_image_sources)->toBe('all');
})->with([
    ['upload_only', ImageSourceType::Media->value],
    ['url_media', ImageSourceType::Upload->value],
]);

it('shows effective language controls without changing locale precedence', function (): void {
    Permission::findOrCreate('View:SettingsPage', 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage');
    config()->set('app.locale', 'en_GB');
    $settings = resolve(CoreSettings::class);
    $settings->default_locale = 'fr';
    $settings->save();

    Livewire::test(SettingsPage::class)
        ->assertSee('data-capell-language-setup', false)
        ->assertSee('en_GB')
        ->assertSchemaComponentStateSet('core.default_locale', 'fr');

    expect(ResolveAdminLocaleForUserAction::run(test()->authenticatedUser()))->toBe('en_GB');
});

it('persists deliberate dashboard layouts through save and revisit', function (bool $enabled): void {
    Permission::findOrCreate('View:SettingsPage', 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage');
    $settings = AdminSettings::instance();
    $settings->enabled_widgets = array_replace($settings->enabled_widgets, ['extensions.stats' => ! $enabled]);
    $settings->save();

    $page = Livewire::test(SettingsPage::class);
    $layout = $page->get('data.admin.widget_layout');
    foreach ($layout as &$widget) {
        $widget['enabled'] = $enabled;
    }

    unset($widget);

    $page->fillForm(['admin' => ['widget_layout' => $layout]])->call('save')->assertHasNoFormErrors();
    Livewire::test(SettingsPage::class)->assertSuccessful();
    $saved = AdminSettings::instance()->refresh()->enabled_widgets;
    foreach ($layout as $widget) {
        expect($saved[$widget['key']])->toBe($enabled);
    }

    expect($saved['extensions.stats'])->toBe(! $enabled);
})->with([true, false]);

it('reviews the explicit main dashboard reset before saving only that scope', function (): void {
    Permission::findOrCreate('View:SettingsPage', 'web');
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage');
    $settings = AdminSettings::instance();
    $settings->enabled_widgets = array_fill_keys(array_keys(AdminSettings::defaultWidgetOrder()), false);
    $settings->save();

    $saved = $settings->enabled_widgets;
    $page = Livewire::test(SettingsPage::class);
    $section = collect($page->instance()->form->getFlatComponents(withHidden: true))->first(
        fn ($component): bool => $component instanceof Section && array_any(
            $component->getHeaderActions(),
            fn (Action|ActionGroup $action): bool => $action instanceof Action && $action->getName() === 'restoreMainDashboardLayout',
        ),
    );
    throw_unless($section instanceof Section, RuntimeException::class);
    $key = $section->getKey();
    throw_unless(is_string($key), RuntimeException::class);
    $page->callAction(TestAction::make('restoreMainDashboardLayout')->schemaComponent(str($key)->after('form.')->toString(), schema: 'form'));
    expect(AdminSettings::instance()->refresh()->enabled_widgets['list_pages'])->toBeFalse();
    $layout = collect($page->get('data.admin.widget_layout'));
    expect($layout->firstWhere('key', 'list_pages')['enabled'])->toBeTrue();
    $page->call('save')->assertHasNoFormErrors();
    expect(AdminSettings::instance()->refresh()->enabled_widgets['list_pages'])->toBeTrue()
        ->and(AdminSettings::instance()->refresh()->enabled_widgets['extensions.stats'])->toBe($saved['extensions.stats']);
});
