<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Filament\Support\Contracts\HasLabel;

enum HeaderPositionEnum: string implements HasLabel
{
    case Static_ = 'static';

    case Fixed = 'fixed';

    case Sticky = 'sticky';

    case ScrollUp = 'scroll_up';

    public function getLabel(): string
    {
        return match ($this) {
            self::Static_ => __('capell-admin::form.header_position_disabled'),
            self::Fixed => __('capell-admin::form.header_position_fixed'),
            self::Sticky => __('capell-admin::form.header_position_sticky'),
            self::ScrollUp => __('capell-admin::form.header_position_scroll_up'),
        };
    }
}
