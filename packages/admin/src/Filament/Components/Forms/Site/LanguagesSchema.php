<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Site;

use Capell\Admin\Filament\Actions\Site\CreateLanguageAction;
use Filament\Schemas\Components\Section;

class LanguagesSchema
{
    /**
     * @return array<int, mixed>
     */
    public static function make(): array
    {
        return [
            Section::make(__('capell-admin::form.languages'))
                ->description(__('capell-admin::generic.site_languages_description'))
                ->compact()
                ->columnSpanFull()
                ->columns()
                ->headerActions([
                    CreateLanguageAction::make(),
                ])
                ->schema([
                    LanguageSelect::make('language_id'),
                    AdditionalSiteLanguages::make('languages')
                        ->key('languages')
                        ->dehydrated(false),
                ]),
        ];
    }
}
