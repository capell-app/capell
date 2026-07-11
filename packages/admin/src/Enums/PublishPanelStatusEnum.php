<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * The four states the publish panel renders as a status pill.
 *
 * Unlike {@see PublishStatusEnum} (which collapses every
 * future publish date into a single "pending" case), this enum distinguishes a
 * genuine future schedule from the far-future draft sentinel — the distinction
 * the WordPress-style panel needs to label and colour itself correctly.
 */
enum PublishPanelStatusEnum: string implements HasColor, HasIcon, HasLabel
{
    case draft = 'draft';

    case scheduled = 'scheduled';

    case published = 'published';

    case expired = 'expired';

    public function getLabel(): string
    {
        return match ($this) {
            self::draft => __('capell-admin::publish_panel.status_draft'),
            self::scheduled => __('capell-admin::publish_panel.status_scheduled_publish'),
            self::published => __('capell-admin::publish_panel.status_published'),
            self::expired => __('capell-admin::publish_panel.status_unpublished'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::draft => 'gray',
            self::scheduled => 'warning',
            self::published => 'success',
            self::expired => 'danger',
        };
    }

    public function getIcon(): BackedEnum
    {
        return match ($this) {
            self::draft => Heroicon::PencilSquare,
            self::scheduled => Heroicon::Clock,
            self::published => Heroicon::CheckCircle,
            self::expired => Heroicon::ExclamationTriangle,
        };
    }
}
