<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Theme;

use Filament\Forms\Components\TextInput;

class AssetsBuildPathTextInput extends TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.build_path'))
            ->hint(__('capell-admin::generic.build_path_info'))
            ->placeholder('build');
    }

    public static function getDefaultName(): ?string
    {
        return 'assets_path';
    }
}
