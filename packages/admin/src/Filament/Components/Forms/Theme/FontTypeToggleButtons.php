<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Theme;

use Capell\Admin\Enums\ThemeFontTypeEnum;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Set;

class FontTypeToggleButtons extends ToggleButtons
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.font_type'))
            ->grouped()
            ->default(ThemeFontTypeEnum::Url->value)
            ->options(ThemeFontTypeEnum::options())
            ->afterStateUpdated(function (Set $set): void {
                $set('url', null);
                $set('files', null);
            });
    }

    public static function getDefaultName(): ?string
    {
        return 'type';
    }
}
