<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Configurators\Blueprints;

use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Contracts\ConfiguratorTypeEnumInterface;
use Capell\Admin\Enums\BlueprintCreationModeEnum;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Enums\SchemaExtenderEnum;
use Capell\Admin\Filament\Components\Forms\ConfiguratorSelect;
use Capell\Admin\Filament\Components\Forms\DefaultToggle;
use Capell\Admin\Filament\Components\Forms\IconPicker;
use Capell\Admin\Filament\Components\Forms\KeyTextInput;
use Capell\Admin\Filament\Components\Forms\NameKeyGroup;
use Capell\Admin\Filament\Components\Forms\StatusToggle;
use Capell\Admin\Filament\Concerns\HasConfigurator;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;
use RuntimeException;

class DefaultBlueprintConfigurator implements ConfiguratorInterface
{
    use HasConfigurator;

    protected const string DEFAULT_ADMIN_CONFIGURATOR = 'Default';

    protected static ConfiguratorTypeEnumInterface $configuratorType = ConfiguratorTypeEnum::Blueprint;

    /** @return iterable<int, mixed> */
    public static function getExtenders(): iterable
    {
        return app()->tagged(SchemaExtenderEnum::Type->value);
    }

    /** @return array<int, mixed> */
    public function make(Schema $schema): array
    {
        return $this->getFormSchema($schema);
    }

    /** @return array<int, mixed> */
    protected function getFormSchema(Schema $schema): array
    {
        return [
            ...$this->settingsSchema($schema),
            ...$this->getAdminSchema(),
            ...$this->statusSchema(),
        ];
    }

    protected function getGroupField(): Component
    {
        return TextInput::make('group')
            ->label(__('capell-admin::form.group'))
            ->hint(__('capell-admin::generic.type_group_info'));
    }

    /** @return array<int, mixed> */
    protected function settingsSchema(Schema $schema): array
    {
        if ($this->context?->embeddedSelectEdit === true) {
            return [];
        }

        return [
            Radio::make('creation_mode')
                ->label(__('capell-admin::form.creation_mode'))
                ->options(BlueprintCreationModeEnum::class)
                ->descriptions([
                    BlueprintCreationModeEnum::Basic->value => __('capell-admin::generic.type_creation_mode_basic_description'),
                    BlueprintCreationModeEnum::Custom->value => __('capell-admin::generic.type_creation_mode_custom_description'),
                ])
                ->default(BlueprintCreationModeEnum::Basic->value)
                ->afterStateHydrated(function (Radio $component, mixed $state): void {
                    if ($state === null) {
                        $component->state(BlueprintCreationModeEnum::Basic->value);
                    }
                })
                ->live()
                ->columnSpanFull()
                ->visible(fn (string $operation): bool => in_array($operation, ['create', 'createOption'], true)),

            Section::make(__('capell-admin::form.type_details'))
                ->description(__('capell-admin::generic.type_definition_description'))
                ->compact()
                ->columnSpanFull()
                ->columns()
                ->schema([
                    NameKeyGroup::make(
                        modifyKey: fn (KeyTextInput $component): KeyTextInput => $component->unique(
                            table: Blueprint::class,
                            ignoreRecord: $schema->getOperation() !== 'replicate',
                            modifyRuleUsing: function (?Unique $rule, Get $get): Unique {
                                throw_if(! $rule instanceof Unique, RuntimeException::class, 'Unique validation rule could not be resolved.');

                                return $rule->where('type', $get('type'))
                                    ->withoutTrashed();
                            },
                        ),
                    ),

                    Select::make('type')
                        ->label(__('capell-admin::form.type'))
                        ->required()
                        ->reactive()
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            $set('admin.configurator', null);
                            $set('admin.type_configurator', $this->defaultBlueprintConfiguratorFor($state));
                        })
                        ->options(
                            fn (): array => CapellCore::getPageTypes()
                                ->mapWithKeys(fn (PageTypeData $type): array => [$type->name => $type->getLabel()])
                                ->sort()
                                ->all(),
                        ),

                    $this->getGroupField()
                        ->visible(fn (Get $get): bool => ! $this->isBasicCreationMode($get)),

                    TextInput::make('order')
                        ->label(__('capell-admin::form.order'))
                        ->required()
                        ->numeric()
                        ->default(function (): int {
                            /** @var class-string<Blueprint> $model */
                            $model = Blueprint::class;

                            return $model::query()->max('order') + 1;
                        })
                        ->minValue(0)
                        ->step(1)
                        ->visible(fn (Get $get): bool => ! $this->isBasicCreationMode($get)),

                    IconPicker::make('admin.icon')
                        ->label(__('capell-admin::form.admin_icon'))
                        ->helperText(__('capell-admin::generic.admin_icon_info'))
                        ->visible(fn (Get $get): bool => $this->isBasicCreationMode($get)),

                    Textarea::make('admin.notes')
                        ->label(__('capell-admin::form.description'))
                        ->helperText(__('capell-admin::generic.type_description_info'))
                        ->rows(2)
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => $this->isBasicCreationMode($get)),
                ]),
        ];
    }

    /** @return array<int, mixed> */
    protected function getAdminSchema(): array
    {
        return [
            Section::make(__('capell-admin::form.admin_setup'))
                ->description(__('capell-admin::generic.type_admin_setup_description'))
                ->statePath('admin')
                ->columnSpanFull()
                ->compact()
                ->columns()
                ->visible(fn (Get $get): bool => ! $this->isBasicCreationMode($get))
                ->schema([
                    $this->blueprintConfiguratorSelect(),
                    ConfiguratorSelect::make('configurator')
                        ->label(__('capell-admin::form.admin_form_configurator'))
                        ->helperText(__('capell-admin::generic.admin_form_configurator_info'))
                        ->default(self::DEFAULT_ADMIN_CONFIGURATOR)
                        ->setupOptions(fn (Get $get): string => str($get('../type'))->ucfirst()->plural()->toString())
                        ->withCreateConfiguratorAction(fn (Get $get): string => str($get('../type'))->ucfirst()->plural()->toString()),
                    IconPicker::make('icon')
                        ->label(__('capell-admin::form.admin_icon'))
                        ->helperText(__('capell-admin::generic.admin_icon_info')),
                    Textarea::make('notes')
                        ->label(__('capell-admin::form.description'))
                        ->helperText(__('capell-admin::generic.type_description_info'))
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ];
    }

    protected function blueprintConfiguratorSelect(?string $default = null): ConfiguratorSelect
    {
        return ConfiguratorSelect::make('type_configurator')
            ->label(__('capell-admin::form.type_configurator'))
            ->helperText(__('capell-admin::generic.type_configurator_info'))
            ->default(fn (Get $get): string => $default ?? $this->defaultBlueprintConfiguratorFor($get('../type')))
            ->live()
            ->setupOptions(ConfiguratorTypeEnum::Blueprint);
    }

    protected function defaultBlueprintConfiguratorFor(?string $type): string
    {
        $componentName = CapellCore::getPageTypes()
            ->first(fn (PageTypeData $pageType): bool => $pageType->name === $type)
            ?->getComponentName();

        $candidates = collect([
            $componentName,
            $type,
            is_string($componentName) ? str($componentName)->studly()->toString() : null,
            is_string($type) ? str($type)->studly()->toString() : null,
            is_string($type) ? str($type)->lower()->toString() : null,
            self::DEFAULT_ADMIN_CONFIGURATOR,
        ])->filter()->unique();

        foreach ($candidates as $candidate) {
            if (AdminSurfaceLookup::hasConfigurator(ConfiguratorTypeEnum::Blueprint, $candidate)) {
                return $candidate;
            }
        }

        return self::DEFAULT_ADMIN_CONFIGURATOR;
    }

    /** @return array<int, mixed> */
    protected function statusSchema(): array
    {
        return [
            Section::make(__('capell-admin::form.type_availability'))
                ->description(__('capell-admin::generic.type_availability_description'))
                ->columnSpanFull()
                ->compact()
                ->schema([
                    Grid::make()
                        ->schema([
                            DefaultToggle::make('default')
                                ->visible(fn (Get $get): bool => ! $this->isBasicCreationMode($get)),
                            StatusToggle::make('status'),
                        ]),
                ]),
        ];
    }

    private function isBasicCreationMode(Get $get): bool
    {
        $creationMode = $get('creation_mode');

        return $creationMode === BlueprintCreationModeEnum::Basic
            || $creationMode === BlueprintCreationModeEnum::Basic->value;
    }
}
