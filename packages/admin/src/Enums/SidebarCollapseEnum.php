<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Contracts\HasLabel;

enum SidebarCollapseEnum: string implements HasLabel
{
    case None = 'none';

    case Collapsible = 'collapsible';

    case FullyCollapsible = 'fully_collapsible';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => __('capell-admin::generic.none'),
            self::Collapsible => __('capell-admin::generic.collapsible'),
            self::FullyCollapsible => __('capell-admin::generic.fully_collapsible'),
        };
    }
}
