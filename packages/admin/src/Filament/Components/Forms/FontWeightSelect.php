<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Core\Enums\FontWeightEnum;
use Filament\Forms\Components\Select;

class FontWeightSelect extends Select
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.font_weight'))
            ->options($this->getFontOptions());
    }

    public static function getDefaultName(): ?string
    {
        return 'weight';
    }

    /**
     * @return array<string, string>
     */
    protected function getFontOptions(): array
    {
        $options = [];

        foreach (FontWeightEnum::cases() as $weight) {
            $options[$weight->value] = $this->getWeightOptionLabel($weight);
        }

        /** @var array<string, string> $options */
        return $options;
    }

    protected function getWeightOptionLabel(FontWeightEnum $weight): string
    {
        return match ($weight) {
            FontWeightEnum::Normal => __('capell-admin::form.font_weight_normal'),
            FontWeightEnum::Bold => __('capell-admin::form.font_weight_bold'),
            default => $weight->value,
        };
    }
}
