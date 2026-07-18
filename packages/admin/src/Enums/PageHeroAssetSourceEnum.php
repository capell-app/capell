<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum PageHeroAssetSourceEnum: string implements HasLabel
{
    use HasEnumOptions;

    case Element = 'element';
    case Page = 'page';
    case Mixed = 'mixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Element => (string) __('capell-admin::form.hero_asset_source_element'),
            self::Page => (string) __('capell-admin::form.hero_asset_source_page'),
            self::Mixed => (string) __('capell-admin::form.hero_asset_source_mixed'),
        };
    }
}
