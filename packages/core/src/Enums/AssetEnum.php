<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use BackedEnum;
use Capell\Core\Models\Page;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

enum AssetEnum: string implements HasColor, HasIcon, HasLabel
{
    case Page = 'page';

    public function getColor(): string
    {
        return match ($this) {
            self::Page => config('capell-admin.assets.page.color', 'primary'),
        };
    }

    public function getIcon(): string|BackedEnum
    {
        return match ($this) {
            self::Page => config('capell-admin.assets.page.icon', Heroicon::OutlinedRectangleStack),
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Page => __('capell::generic.page'),
        };
    }

    /**
     * @return class-string<Model>
     */
    public function getModel(): string
    {
        return match ($this) {
            self::Page => config('capell-core.assets.page.model', Page::class),
        };
    }

    public function hasTranslations(): true
    {
        return match ($this) {
            self::Page => true,
        };
    }
}
