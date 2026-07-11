<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Site;

use Capell\Core\Models\Language;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class LanguageSelect extends Select
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.default_language'))
            ->relationship('language', 'name')
            ->reactive()
            ->required()
            ->default(fn (): ?int => Language::getDefault()?->id)
            ->afterStateUpdated(function (?int $state, Get $get, Set $set): void {
                if ($state === null || $state === 0) {
                    return;
                }

                $languages = (array) $get('languages');

                if (! in_array($state, $languages, true)) {
                    $languages[] = $state;
                }

                $set('languages', array_values(array_diff($languages, [$state])));
            });
    }
}
