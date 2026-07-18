<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Core\Enums\Concerns\HasEnumOptions;
use Filament\Support\Contracts\HasLabel;

enum PageUrlTypeEnum: string implements HasLabel
{
    use HasEnumOptions;

    /** Default = UI sentinel dehydrating to null. */
    case Default = 'default';
    case Alias = 'alias';
    case Redirect = 'redirect';

    public function getLabel(): string
    {
        return match ($this) {
            self::Default => (string) __('capell-admin::generic.default'),
            self::Alias => (string) __('capell-admin::generic.alias'),
            self::Redirect => (string) __('capell-admin::generic.redirect'),
        };
    }
}
