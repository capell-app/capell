<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Core\Enums\FontStyleEnum;
use Filament\Forms\Components\Select;

class FontStyleSelect extends Select
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.font_style'))
            ->options($this->getFontStyleOptions());
    }

    public static function getDefaultName(): ?string
    {
        return 'style';
    }

    /**
     * @return array<string, string>
     */
    protected function getFontStyleOptions(): array
    {
        return collect(FontStyleEnum::cases())
            ->mapWithKeys(fn (FontStyleEnum $weight): array => [$weight->value => $this->getStyleOptionLabel($weight)])
            ->all();
    }

    protected function getStyleOptionLabel(FontStyleEnum $style): string
    {
        return match ($style) {
            FontStyleEnum::Normal => __('capell-admin::form.font_style_normal'),
            FontStyleEnum::Italic => __('capell-admin::form.font_style_italic'),
        };
    }
}
