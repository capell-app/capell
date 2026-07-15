<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Settings;

use Capell\Admin\Enums\EditorEnum;
use Capell\Admin\Enums\ThemeStudioCardDensityEnum;
use Capell\Admin\Enums\ThemeStudioHeadingScaleEnum;
use Capell\Admin\Enums\ThemeStudioOverlayTreatmentEnum;
use Capell\Admin\Enums\ThemeStudioRadiusEnum;
use Capell\Admin\Filament\Contracts\HasSchema;
use Capell\Admin\Filament\Pages\SettingsPage;
use Capell\Admin\Filament\Settings\ThemeStudioSettingsSchema;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Core\ThemeStudio\Settings\ThemeStudioSettings;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Schemas\Schema;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)
    ->group('admin', 'settings');

beforeEach(function (): void {
    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);
    test()->actingAsAdmin();
    test()->authenticatedUser()->givePermissionTo('View:SettingsPage');
});

it('displays registered first-party settings groups as tabs', function (): void {
    Livewire::test(SettingsPage::class)
        ->assertSuccessful()
        ->assertSee(__('capell-admin::form.theme_studio_brand_colours'))
        ->assertSee(__('capell-admin::generic.theme_studio_brand_colours_info'))
        ->assertSee(__('capell-admin::form.theme_studio_presentation_defaults'))
        ->assertSee(__('capell-admin::generic.theme_studio_presentation_defaults_info'))
        ->assertSee(__('capell-admin::form.theme_studio_radius_helper'))
        ->assertFormFieldExists('core.default_locale')
        ->assertFormFieldExists('admin.html_editor')
        ->assertFormFieldExists('theme_studio.brandProfile.surfaceColor')
        ->assertFormFieldExists('theme_studio.brandProfile.radius');
});

it('loads settings form data from registered first-party groups', function (): void {
    Livewire::test(SettingsPage::class)
        ->assertSuccessful()
        ->assertSet('data.core.default_locale', 'en')
        ->assertSet('data.admin.html_editor', EditorEnum::RichEditor->value);
});

it('saves settings to their respective group settings classes', function (): void {
    Livewire::test(SettingsPage::class)
        ->assertSuccessful()
        ->fillForm([
            'core' => ['default_locale' => 'de'],
            'admin' => ['html_editor' => EditorEnum::TinyMCE],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas(
        'settings',
        [
            'group' => 'core',
            'name' => 'default_locale',
            'payload' => json_encode('de'),
        ],
    );

    assertDatabaseHas(
        'settings',
        [
            'group' => 'admin',
            'name' => 'html_editor',
            'payload' => json_encode(EditorEnum::TinyMCE->value),
        ],
    );
});

it('registry contains core group after boot', function (): void {
    $registry = resolve(SettingsSchemaRegistry::class);

    expect($registry->hasGroup('core'))->toBeTrue()
        ->and($registry->getSettingsClass('core'))->not->toBeNull()
        ->and($registry->getSchemas('core'))->not->toBeEmpty();
});

it('registry exposes theme studio as first-party settings', function (): void {
    $registry = resolve(SettingsSchemaRegistry::class);

    expect($registry->getFirstPartyGroups())->toContain('theme_studio')
        ->and($registry->getSettingsClass('theme_studio'))->toBe(ThemeStudioSettings::class)
        ->and($registry->getSchemas('theme_studio'))->toContain(ThemeStudioSettingsSchema::class);
});

it('blocks theme studio tokens that would require public fallback tokens', function (): void {
    $settings = resolve(ThemeStudioSettings::class);
    $existingBrandProfile = $settings->brandProfile;
    $existingBrandProfile['headingFont'] = 'playfair';
    $existingBrandProfile['bodyFont'] = 'sora';
    $settings->brandProfile = $existingBrandProfile;
    $settings->save();

    $submittedBrandProfile = [
        'primaryColor' => $existingBrandProfile['primaryColor'],
        'accentColor' => $existingBrandProfile['accentColor'],
        'neutralColor' => $existingBrandProfile['neutralColor'],
        'surfaceColor' => '#ffffff',
        'foregroundColor' => '#ffffff',
        'radius' => 'lg',
        'headingScale' => 'expressive',
        'cardDensity' => 'spacious',
        'overlayTreatment' => 'strong',
    ];
    Livewire::test(SettingsPage::class)
        ->assertSuccessful()
        ->fillForm([
            'theme_studio' => [
                'brandProfile' => $submittedBrandProfile,
            ],
        ])
        ->call('save')
        ->assertHasFormErrors([
            'theme_studio.brandProfile.primaryColor',
            'theme_studio.brandProfile.accentColor',
            'theme_studio.brandProfile.neutralColor',
            'theme_studio.brandProfile.surfaceColor',
            'theme_studio.brandProfile.foregroundColor',
        ])
        ->assertNotNotified(__('capell-admin::message.theme_studio_token_fallback_heading'))
        ->assertNotNotified(__('filament-spatie-laravel-settings-plugin::pages/settings-page.notifications.saved.title'));

    assertDatabaseHas(
        'settings',
        [
            'group' => 'theme_studio',
            'name' => 'brandProfile',
            'payload' => json_encode($existingBrandProfile),
        ],
    );
});

it('saves partial theme studio tokens without dropping existing profile keys', function (): void {
    $settings = resolve(ThemeStudioSettings::class);
    $existingBrandProfile = $settings->brandProfile;
    $existingBrandProfile['headingFont'] = 'playfair';
    $existingBrandProfile['bodyFont'] = 'sora';
    $settings->brandProfile = $existingBrandProfile;
    $settings->save();

    $submittedBrandProfile = [
        'primaryColor' => '#111827',
        'accentColor' => '#92400e',
        'neutralColor' => '#374151',
        'surfaceColor' => '#ffffff',
        'foregroundColor' => '#111827',
        'radius' => ThemeStudioRadiusEnum::Large,
        'headingScale' => ThemeStudioHeadingScaleEnum::Expressive,
        'cardDensity' => ThemeStudioCardDensityEnum::Spacious,
        'overlayTreatment' => ThemeStudioOverlayTreatmentEnum::Strong,
    ];
    $expectedStoredBrandProfile = array_merge($existingBrandProfile, [
        ...$submittedBrandProfile,
        'radius' => ThemeStudioRadiusEnum::Large->value,
        'headingScale' => ThemeStudioHeadingScaleEnum::Expressive->value,
        'cardDensity' => ThemeStudioCardDensityEnum::Spacious->value,
        'overlayTreatment' => ThemeStudioOverlayTreatmentEnum::Strong->value,
        'customTokens' => [],
    ]);

    Livewire::test(SettingsPage::class)
        ->assertSuccessful()
        ->fillForm([
            'theme_studio' => [
                'brandProfile' => $submittedBrandProfile,
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    assertDatabaseHas(
        'settings',
        [
            'group' => 'theme_studio',
            'name' => 'brandProfile',
            'payload' => json_encode($expectedStoredBrandProfile),
        ],
    );
});

it('allows adding custom schemas to existing group', function (): void {
    $registry = resolve(SettingsSchemaRegistry::class);

    $customSchema = new class implements HasSchema
    {
        public static function make(Schema $schema): array
        {
            return [];
        }
    };

    $beforeCount = count($registry->getSchemas('admin'));
    $registry->register('admin', $customSchema::class, 'custom');

    expect($registry->getSchemas('admin'))
        ->toHaveKey('custom')
        ->toHaveCount($beforeCount + 1);
});

it('allows replacing existing schema', function (): void {
    $registry = resolve(SettingsSchemaRegistry::class);

    $replacementSchema = new class implements HasSchema
    {
        public static function make(Schema $schema): array
        {
            return [];
        }
    };

    $originalSchemas = $registry->getSchemas('admin');
    expect($originalSchemas)->not->toBeEmpty();

    $originalKey = array_key_first($originalSchemas);
    assert(is_string($originalKey));

    $originalClass = $originalSchemas[$originalKey];

    $registry->replace('admin', $replacementSchema::class, $originalKey);

    expect($registry->getSchema('admin', $originalKey))
        ->toBe($replacementSchema::class)
        ->not->toBe($originalClass);
});

it('allows removing schema from group', function (): void {
    $registry = resolve(SettingsSchemaRegistry::class);

    $mockSchema = new class implements HasSchema
    {
        public static function make(Schema $schema): array
        {
            return [];
        }
    };

    $registry->register('admin', $mockSchema::class, 'removable');

    expect($registry->hasGroup('admin'))->toBeTrue()
        ->and($registry->getSchema('admin', 'removable'))->toBe($mockSchema::class);

    $registry->remove('admin', 'removable');

    expect($registry->getSchema('admin', 'removable'))->toBeNull();
});
