<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Sites\Schemas;

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Filament\Components\Forms\NameInput;
use Capell\Admin\Filament\Components\Forms\Site\LanguagesSchema;
use Capell\Admin\Filament\Components\Forms\ThemeSelect;
use Capell\Admin\Filament\Contracts\FormConfigurator;
use Capell\Core\Enums\SiteAiDisclosure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class DefaultSiteForm implements FormConfigurator
{
    public static function configure(Schema $schema, ?ConfiguratorContextData $context = null): Schema
    {
        return $schema->components([
            NameInput::make('name')
                ->unique(
                    ignoreRecord: $schema->getOperation() !== 'replicate',
                    modifyRuleUsing: fn (Unique $rule) => $rule->withoutTrashed(),
                ),
            TextInput::make('url')
                ->label(__('capell-admin::form.url'))
                ->required()
                ->url(),
            ...LanguagesSchema::make(),
            ThemeSelect::make('theme_id')
                ->required()
                ->when(
                    $schema->isCreating(),
                    fn (ThemeSelect $component): ThemeSelect => $component->withCreateForm(),
                    fn (ThemeSelect $component): ThemeSelect => $component->withEditForm(),
                ),
            Select::make('meta.' . SiteAiDisclosure::META_KEY)
                ->label(__('capell-admin::form.ai_disclosure'))
                ->helperText(__('capell-admin::form.ai_disclosure_help'))
                ->options([
                    SiteAiDisclosure::Off->value => __('capell-admin::form.ai_disclosure_off'),
                    SiteAiDisclosure::Marker->value => __('capell-admin::form.ai_disclosure_marker'),
                ])
                ->default(SiteAiDisclosure::Off->value)
                ->selectablePlaceholder(false),
        ]);
    }
}
