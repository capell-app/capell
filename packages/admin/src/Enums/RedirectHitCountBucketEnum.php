<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Contracts\HasLabel;

enum RedirectHitCountBucketEnum: string implements HasLabel
{
    case None = 'none';
    case Any = 'any';
    case TenPlus = 'ten_plus';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => (string) __('capell-admin::table.hit_count_bucket_none'),
            self::Any => (string) __('capell-admin::table.hit_count_bucket_any'),
            self::TenPlus => (string) __('capell-admin::table.hit_count_bucket_ten_plus'),
        };
    }
}
