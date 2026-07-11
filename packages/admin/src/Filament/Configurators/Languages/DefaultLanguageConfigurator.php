<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Configurators\Languages;

use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Contracts\ConfiguratorTypeEnumInterface;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Enums\SchemaExtenderEnum;
use Capell\Admin\Filament\Components\Forms\DefaultToggle;
use Capell\Admin\Filament\Components\Forms\StatusToggle;
use Capell\Admin\Filament\Concerns\HasConfigurator;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class DefaultLanguageConfigurator implements ConfiguratorInterface
{
    use HasConfigurator;

    protected static ConfiguratorTypeEnumInterface $configuratorType = ConfiguratorTypeEnum::Language;

    /** @return iterable<int, mixed> */
    public static function getExtenders(): iterable
    {
        return app()->tagged(SchemaExtenderEnum::Language->value);
    }

    /** @return array<int, mixed> */
    public function make(Schema $schema): array
    {
        return $this->getFormSchema($schema);
    }

    protected function makeFlagField(): Field
    {
        return TextInput::make('flag')
            ->label(__('capell-admin::form.flag'))
            ->helperText(__('capell-admin::generic.language_flag_info'))
            ->required()
            ->maxLength(12);
    }

    /** @return array<int, mixed> */
    private function getFormSchema(Schema $schema): array
    {
        return [
            Section::make(__('capell-admin::form.language_identity'))
                ->description(__('capell-admin::generic.language_identity_description'))
                ->compact()
                ->columns()
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')
                        ->label(__('capell-admin::form.name'))
                        ->required(),

                    TextInput::make('code')
                        ->label(__('capell-admin::form.code'))
                        ->helperText(__('capell-admin::generic.iso_639_1'))
                        ->required()
                        ->maxLength(12)
                        ->unique(
                            table: Language::class,
                            ignoreRecord: $schema->getOperation() !== 'replicate',
                            modifyRuleUsing: fn (Unique $rule) => $rule->withoutTrashed(),
                        ),

                    TextInput::make('locale')
                        ->label(__('capell-admin::form.locale'))
                        ->helperText(__('capell-admin::generic.locale_info'))
                        ->required()
                        ->maxLength(12)
                        ->unique(
                            table: Language::class,
                            ignoreRecord: $schema->getOperation() !== 'replicate',
                            modifyRuleUsing: fn (Unique $rule) => $rule->withoutTrashed(),
                        ),

                    $this->makeFlagField(),

                    TextInput::make('order')
                        ->label(__('capell-admin::form.order'))
                        ->helperText(__('capell-admin::generic.language_order_info'))
                        ->required()
                        ->numeric()
                        ->default(function (): int {
                            /** @var class-string<Language> $model */
                            $model = Language::class;

                            return $model::query()->enabled()->max('order') + 1;
                        })
                        ->minValue(0)
                        ->step(1),
                ]),

            Section::make(__('capell-admin::form.language_availability'))
                ->description(__('capell-admin::generic.language_availability_description'))
                ->columns()
                ->columnSpanFull()
                ->schema([
                    Group::make()
                        ->extraAttributes(['class' => 'filament-form-compact'])
                        ->schema([
                            DefaultToggle::make('default'),
                            Group::make()
                                ->statePath('meta')
                                ->schema([
                                    Toggle::make('rtl')
                                        ->label(__('capell-admin::form.right_to_left')),
                                ]),
                        ]),
                    StatusToggle::make('status'),
                ]),

            Section::make(__('capell-admin::form.language_site_setup'))
                ->description(__('capell-admin::generic.language_site_setup_description'))
                ->visibleOn(['create', 'createOption', 'replicate'])
                ->columnSpanFull()
                ->columns(1)
                ->schema([
                    Checkbox::make('setup')
                        ->label(__('capell-admin::form.language_setup'))
                        ->helperText(__('capell-admin::generic.language_setup_info'))
                        ->validationAttribute(__('capell-admin::form.auto_languages'))
                        ->inline()
                        ->default(false),
                    CheckboxList::make('setup_sites')
                        ->label(__('capell-admin::form.sites'))
                        ->helperText(__('capell-admin::generic.language_setup_sites_info'))
                        ->options(function (): array {
                            /** @var class-string<Site> $model */
                            $model = Site::class;

                            return SiteScope::applyForCurrentActor($model::query(), 'id')
                                ->select(['name', 'id'])
                                ->ordered()
                                ->pluck('name', 'id')
                                ->toArray();
                        })
                        ->visibleJs(<<<'JS'
                             $get('setup')
                        JS)
                        ->columns(3)
                        ->requiredIf('setup', true),
                ]),
        ];
    }
}
