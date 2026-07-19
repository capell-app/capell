<?php

declare(strict_types=1);

use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Filament\Components\Forms\Editor\ContentBuilder;
use Capell\Admin\Filament\Components\Forms\Presentation\PresentationSettingsSchema;
use Capell\Admin\Filament\Components\Forms\Presentation\ResourceSettingsSchema;
use Capell\Core\Enums\PresentationDeliveryMode;
use Capell\Core\Enums\PresentationLazyPolicy;
use Capell\Core\Enums\PresentationLoadingStrategy;
use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;

it('hides advanced presentation controls from normal editors', function (): void {
    Auth::login(test()->createUser());

    $schema = PresentationSettingsSchema::make();
    $basicFields = sectionFieldNames($schema[0]);
    $advancedFields = sectionFieldNames($schema[1]);

    expect($schema)->toHaveCount(2)
        ->and($basicFields)->toContain('delivery_mode', 'loading_strategy', 'lazy_policy')
        ->and($advancedFields)->not->toContain('delivery_mode', 'loading_strategy')
        ->and(PresentationSettingsSchema::canViewAdvanced())->toBeFalse();
});

it('shows advanced presentation controls to permitted editors', function (): void {
    Permission::findOrCreate(CapellPermission::ManageAdvancedPresentationSettings->name());
    Auth::login(test()->createUserWithPermission(CapellPermission::ManageAdvancedPresentationSettings->name()));

    $schema = PresentationSettingsSchema::make();

    expect($schema)->toHaveCount(2)
        ->and(PresentationSettingsSchema::canViewAdvanced())->toBeTrue();
});

it('maps lazy policy choices onto existing presentation fields', function (): void {
    expect(PresentationSettingsSchema::lazyPolicyOptions())->toBe(PresentationLazyPolicy::options())
        ->and(PresentationSettingsSchema::presentationSettingsForLazyPolicy('server-rendered'))->toBe([
            'delivery_mode' => PresentationDeliveryMode::ServerRendered->value,
            'loading_strategy' => PresentationLoadingStrategy::Eager->value,
        ])
        ->and(PresentationSettingsSchema::presentationSettingsForLazyPolicy('visible'))->toBe([
            'delivery_mode' => PresentationDeliveryMode::LazyFragment->value,
            'loading_strategy' => PresentationLoadingStrategy::Visible->value,
        ])
        ->and(PresentationSettingsSchema::presentationSettingsForLazyPolicy('interaction'))->toBe([
            'delivery_mode' => PresentationDeliveryMode::LazyFragment->value,
            'loading_strategy' => PresentationLoadingStrategy::Interaction->value,
        ])
        ->and(PresentationSettingsSchema::presentationSettingsForLazyPolicy('idle'))->toBe([
            'delivery_mode' => PresentationDeliveryMode::LazyFragment->value,
            'loading_strategy' => PresentationLoadingStrategy::Idle->value,
        ])
        ->and(PresentationSettingsSchema::lazyPolicyFor(PresentationDeliveryMode::LazyFragment->value, PresentationLoadingStrategy::Interaction->value))->toBe('interaction')
        ->and(PresentationSettingsSchema::lazyPolicyFor(PresentationDeliveryMode::ServerRendered->value, PresentationLoadingStrategy::Visible->value))->toBe('server-rendered');
});

it('shows resource controls with package resource group options for advanced editors', function (): void {
    Permission::findOrCreate(CapellPermission::ManageAdvancedPresentationSettings->name());
    Auth::login(test()->createUserWithPermission(CapellPermission::ManageAdvancedPresentationSettings->name()));

    app()->instance('capell.frontend.resource-group-options', fn (): array => [
        'package.gallery' => 'Gallery resources',
    ]);

    $schema = ResourceSettingsSchema::make();
    $fields = sectionFieldNames($schema[0]);

    expect($fields)->toContain('groups', 'loading_overrides')
        ->and(ResourceSettingsSchema::resourceGroupOptions())->toHaveKey('package.gallery', 'Gallery resources');
});

it('keeps selected resource metadata under the capell resources state', function (): void {
    $builder = ContentBuilder::make('content');
    $state = [
        [
            'type' => 'gallery',
            'data' => [
                '__capell' => [
                    'presentation' => [
                        'delivery_mode' => PresentationDeliveryMode::LazyFragment->value,
                        'loading_strategy' => PresentationLoadingStrategy::Visible->value,
                    ],
                    'resources' => [
                        'groups' => ['package.gallery'],
                        'loading_overrides' => [
                            [
                                'group' => 'package.gallery',
                                'loading_strategy' => PresentationLoadingStrategy::Idle->value,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    expect($builder->mutateDehydratedState($state)[0]['data']['__capell']['resources'])->toBe([
        'groups' => ['package.gallery'],
        'loading_overrides' => [
            [
                'group' => 'package.gallery',
                'loading_strategy' => PresentationLoadingStrategy::Idle->value,
            ],
        ],
    ]);
});

function fieldName(Field $field): string
{
    $property = new ReflectionProperty($field, 'name');

    return (string) $property->getValue($field);
}

/**
 * @return list<string>
 */
function sectionFieldNames(object $section): array
{
    $property = new ReflectionProperty($section, 'childComponents');
    $components = $property->getValue($section)['default'] ?? [];

    /** @var list<string> $fieldNames */
    $fieldNames = collect($components)
        ->filter(fn (object $component): bool => $component instanceof Field)
        ->map(fn (Field $component): string => fieldName($component))
        ->values()
        ->all();

    return $fieldNames;
}
