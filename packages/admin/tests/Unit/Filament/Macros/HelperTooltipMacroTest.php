<?php

declare(strict_types=1);

use Capell\Admin\Filament\Support\HelperText;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

use function PHPUnit\Framework\assertSame;

function mountedHelperTooltipField(string|Closure|null $tooltip): Checkbox
{
    $schema = Schema::make(Livewire::make())
        ->components([
            Checkbox::make('test')
                ->helperTooltip($tooltip),
        ]);

    /** @var Checkbox $field */
    $field = $schema->getComponents()[0];

    return $field;
}

function helperTooltipIcon(Checkbox $field): Icon
{
    $components = $field->getChildSchema(Checkbox::AFTER_LABEL_SCHEMA_KEY)?->getComponents();
    $component = $components[0] ?? null;

    assert($component instanceof Icon);

    return $component;
}

/**
 * @param  array<string, mixed>  $state
 */
function mountedRequiredBasedOnTypeField(array $state, string $fieldName = 'title'): TextInput
{
    $schema = Schema::make(Livewire::make()->data($state))
        ->statePath('data')
        ->components([
            TextInput::make($fieldName)
                ->requiredBasedOnType(),
        ]);

    /** @var TextInput $field */
    $field = $schema->getComponents()[0];

    return $field;
}

it('adds a helper tooltip hint icon to fields', function (): void {
    $field = mountedHelperTooltipField('Join the revolution');

    $component = helperTooltipIcon($field);

    expect($component)->toBeInstanceOf(Icon::class)
        ->and(filamentObjectIcon($component))->toBe(Heroicon::QuestionMarkCircle)
        ->and(filamentObjectTooltip($component))->toBe('Join the revolution');
});

it('accepts a helper tooltip closure', function (): void {
    $field = mountedHelperTooltipField(fn (): string => 'Join the revolution');

    $component = helperTooltipIcon($field);

    expect($component)->toBeInstanceOf(Icon::class)
        ->and(filamentObjectIcon($component))->toBe(Heroicon::QuestionMarkCircle)
        ->and(filamentObjectTooltip($component))->toBe('Join the revolution');
});

it('uses the after label schema for translated helper text', function (): void {
    $schema = Schema::make(Livewire::make())
        ->components([
            HelperText::apply(
                Checkbox::make('test'),
                'capell-admin::form.form_action_position_helper',
            ),
        ]);

    /** @var Checkbox $field */
    $field = $schema->getComponents()[0];

    $component = helperTooltipIcon($field);

    expect($component)->toBeInstanceOf(Icon::class)
        ->and(filamentObjectIcon($component))->toBe(Heroicon::QuestionMarkCircle)
        ->and(filamentObjectTooltip($component))->toBe(filamentText(__('capell-admin::form.form_action_position_helper')));
});

it('honours the admin helper tooltip setting', function (): void {
    $settings = resolve(AdminSettings::class);
    assertSame(true, $settings->show_helper_tooltips);

    $settings->show_helper_tooltips = false;
    $settings->save();

    app()->forgetInstance(AdminSettings::class);

    $field = mountedHelperTooltipField('Join the revolution');

    expect($field->getChildSchema(Checkbox::AFTER_LABEL_SCHEMA_KEY))->toBeNull();
});

it('requires blueprint fields only for site languages that must be translated', function (): void {
    $english = Language::factory()->english()->createOne();
    $welsh = Language::factory()->forCountry('Welsh', 'cy', 'cy', 'gb-wls', order: 2)->createOne();

    $site = Site::factory()
        ->language($english)
        ->withTranslations([$english, $welsh])
        ->createOne([
            'admin' => [
                'require_translations' => [$english->code],
            ],
        ]);

    $blueprint = Blueprint::factory()->page()->createOne([
        'admin' => [
            'required_fields' => ['title'],
        ],
    ]);

    $requiredField = mountedRequiredBasedOnTypeField([
        'site_id' => $site->getKey(),
        'blueprint_id' => $blueprint->getKey(),
        'language_id' => $english->getKey(),
    ]);

    $optionalLanguageField = mountedRequiredBasedOnTypeField([
        'site_id' => $site->getKey(),
        'blueprint_id' => $blueprint->getKey(),
        'language_id' => $welsh->getKey(),
    ]);

    $optionalBlueprintField = mountedRequiredBasedOnTypeField([
        'site_id' => $site->getKey(),
        'blueprint_id' => $blueprint->getKey(),
        'language_id' => $english->getKey(),
    ], 'summary');

    expect($requiredField->isRequired())->toBeTrue()
        ->and($optionalLanguageField->isRequired())->toBeFalse()
        ->and($optionalBlueprintField->isRequired())->toBeFalse();
});
