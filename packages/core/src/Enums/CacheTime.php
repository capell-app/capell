<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Filament\Support\Contracts\HasLabel;

enum CacheTime: string implements HasLabel
{
    case Never = 'never';
    case Hourly = 'hourly';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function getLabel(): string
    {
        return match ($this) {
            self::Never => __('capell::generic.never'),
            self::Hourly => __('capell::generic.hourly'),
            self::Daily => __('capell::generic.daily'),
            self::Weekly => __('capell::generic.weekly'),
            self::Monthly => __('capell::generic.monthly'),
            self::Yearly => __('capell::generic.yearly'),
        };
    }
}
