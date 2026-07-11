<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Theme;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;

class AssetsSchema
{
    /**
     * @return array<int, mixed>
     */
    public static function make(): array
    {
        return [
            Group::make([
                AssetsBuildPathTextInput::make(),
                TextInput::make('critical_asset')
                    ->label(__('capell-admin::form.critical_asset')),
            ]),
            AssetsRepeater::make(),
        ];
    }
}
