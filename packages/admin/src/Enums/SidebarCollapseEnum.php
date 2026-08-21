<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Contracts\HasLabel;

enum SidebarCollapseEnum: string implements HasLabel
{
    case None = 'none';

    case Collapsible = 'collapsible';

    case FullyCollapsible = 'fully_collapsible';

    case HiddenUntilOpened = 'hidden_until_opened';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => __('capell-admin::generic.none'),
            self::Collapsible => __('capell-admin::generic.collapsible'),
            self::FullyCollapsible => __('capell-admin::generic.fully_collapsible'),
            self::HiddenUntilOpened => __('capell-admin::generic.hidden_until_opened'),
        };
    }

    public function isCollapsibleOnDesktop(): bool
    {
        return $this === self::Collapsible;
    }

    public function isFullyCollapsibleOnDesktop(): bool
    {
        return $this === self::FullyCollapsible || $this === self::HiddenUntilOpened;
    }

    public function hidesNavigationUntilOpened(): bool
    {
        return $this === self::HiddenUntilOpened;
    }
}
