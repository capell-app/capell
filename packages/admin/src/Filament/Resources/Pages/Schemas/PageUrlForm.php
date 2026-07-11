<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Schemas;

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Filament\Components\Forms\LanguageSelect;
use Capell\Admin\Filament\Components\Forms\Page\UrlTypeRadio;
use Capell\Admin\Filament\Components\Forms\StatusToggle;
use Capell\Admin\Filament\Contracts\FormConfigurator;
use Capell\Admin\Filament\Resources\Pages\RelationManagers\UrlsRelationManager;
use Capell\Core\Models\PageUrl;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class PageUrlForm implements FormConfigurator
{
    public static function configure(Schema $schema, ?ConfiguratorContextData $context = null): Schema
    {
        return $schema->components([
            Section::make(__('capell-admin::form.page_url.label'))
                ->description(__('capell-admin::generic.page_url_info'))
                ->columnSpanFull()
                ->schema([
                    UrlTypeRadio::make('type'),

                    TextInput::make('url')
                        ->label(__('capell-admin::form.url'))
                        ->helperText(__('capell-admin::generic.page_url_path_info'))
                        ->validationAttribute(__('capell-admin::form.url'))
                        ->required()
                        ->columnSpanFull()
                        ->placeholder('/')
                        ->rules([
                            fn (): Closure => function (string $attribute, string $value, Closure $fail): void {
                                if (in_array(preg_match('/^((^https?:\/\/)|\/).*$/', $value), [0, false], true)) {
                                    $fail(__('capell-admin::message.page_url_invalid'));
                                }
                            },
                        ])
                        ->unique(
                            table: PageUrl::class,
                            ignoreRecord: $schema->getOperation() !== 'replicate',
                            modifyRuleUsing: fn (Unique $rule, Get $get, UrlsRelationManager $livewire): Unique => $rule
                                ->where('language_id', $get('language_id'))
                                ->where('site_id', $livewire->ownerRecord->site_id)
                                ->withoutTrashed(),
                        ),
                ]),

            Section::make(__('capell-admin::form.publish_status'))
                ->description(__('capell-admin::generic.page_url_publish_info'))
                ->columnSpanFull()
                ->columns()
                ->schema([
                    LanguageSelect::make('language_id')
                        ->reactive()
                        ->required()
                        ->withRelationship()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('pageable_type', null);
                            $set('pageable_id', null);
                        }),

                    StatusToggle::make('status'),
                ]),
        ]);
    }
}
