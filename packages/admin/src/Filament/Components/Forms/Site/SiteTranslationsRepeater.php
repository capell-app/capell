<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Site;

use Capell\Admin\Filament\Components\Forms\TranslationsRepeater;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Schema;

class SiteTranslationsRepeater
{
    public static function make(Schema $schema): Repeater
    {
        $operation = $schema->getOperation();

        return TranslationsRepeater::make('translations')
            ->when(
                $operation === 'replicate',
                fn (TranslationsRepeater $repeater): TranslationsRepeater => $repeater->withoutRelationship(),
            );
    }
}
