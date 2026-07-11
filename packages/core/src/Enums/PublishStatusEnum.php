<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use BackedEnum;
use Capell\Core\Models\Contracts\Publishable;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * Visibility/scheduling status for a Publishable model.
 */
enum PublishStatusEnum: string implements HasColor, HasDescription, HasIcon, HasLabel
{
    case deleted = 'Deleted';

    case disabled = 'Disabled';

    case expired = 'Expired';

    case pending = 'Pending';

    case published = 'Published';

    /**
     * @param  Model&Publishable  $model
     */
    public static function fromModel(Model $model): self
    {
        return match (true) {
            $model->trashed() => PublishStatusEnum::deleted,
            $model->isExpired() => PublishStatusEnum::expired,
            $model->isPending() => PublishStatusEnum::pending,
            default => PublishStatusEnum::published,
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::pending => 'warning',
            self::published => 'success',
            self::deleted => 'danger',
            self::expired, self::disabled => 'gray',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::pending => __('capell::generic.pending_description'),
            self::published => __('capell::generic.published_description'),
            self::expired => __('capell::generic.expired_description'),
            self::deleted => __('capell::generic.deleted_description'),
            self::disabled => __('capell::generic.disabled_description'),
        };
    }

    public function getIcon(): BackedEnum
    {
        return match ($this) {
            self::pending => Heroicon::Clock,
            self::published => Heroicon::CheckCircle,
            self::expired => Heroicon::ExclamationTriangle,
            self::deleted => Heroicon::XCircle,
            self::disabled => Heroicon::ShieldExclamation,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::pending => __('capell::generic.pending'),
            self::published => __('capell::generic.published'),
            self::expired => __('capell::generic.expired'),
            self::deleted => __('capell::generic.deleted'),
            self::disabled => __('capell::generic.disabled'),
        };
    }
}
