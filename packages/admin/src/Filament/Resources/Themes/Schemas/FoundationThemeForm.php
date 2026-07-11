<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Themes\Schemas;

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Filament\Components\Forms\KeyTextInput;
use Capell\Admin\Filament\Components\Forms\NameInput;
use Capell\Admin\Filament\Components\Forms\Theme\AssetsBuildPathTextInput;
use Capell\Admin\Filament\Components\Forms\Theme\AssetsRepeater;
use Capell\Admin\Filament\Contracts\FormConfigurator;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Slug\SlugGenerator;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class FoundationThemeForm implements FormConfigurator
{
    public static function configure(Schema $schema, ?ConfiguratorContextData $context = null): Schema
    {
        return $schema->components([
            Section::make(__('capell-admin::form.theme_identity'))
                ->description(__('capell-admin::theme-editor.descriptions.identity'))
                ->columns()
                ->columnSpanFull()
                ->schema([
                    NameInput::make('name')
                        ->unique(
                            ignoreRecord: $schema->getOperation() !== 'replicate',
                            modifyRuleUsing: fn (Unique $rule) => $rule->withoutTrashed(),
                        )
                        ->afterStateUpdatedJs(function (string $operation): string {
                            if (! in_array($operation, ['create', 'createOption', 'replicate'], true)) {
                                return '';
                            }

                            return SlugGenerator::slugifyState("\$state ?? ''", 'key');
                        }),
                    KeyTextInput::make()
                        ->unique(
                            table: Theme::class,
                            modifyRuleUsing: fn (Unique $rule): Unique => $rule->withoutTrashed(),
                        ),
                ]),

            Section::make(__('capell-admin::form.theme_assets'))
                ->description(__('capell-admin::generic.theme_assets_description'))
                ->columns()
                ->columnSpanFull()
                ->schema([
                    AssetsBuildPathTextInput::make(),
                    AssetsRepeater::make()
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
