<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Capell\Admin\Enums\DashboardDateRangeEnum;
use Spatie\LaravelData\Data;

final class DashboardFilterStateData extends Data
{
    /** @param array<string, mixed> $localFilters */
    public function __construct(
        public readonly DashboardDateRangeEnum $period = DashboardDateRangeEnum::ThisWeek,
        public readonly ?string $date = null,
        public readonly ?int $siteId = null,
        public readonly ?string $language = null,
        public readonly bool $refresh = false,
        public readonly array $localFilters = [],
    ) {}

    /** @param array<string, mixed> $filters */
    public static function fromFilters(array $filters): self
    {
        $periodValue = data_get($filters, 'date_range', DashboardDateRangeEnum::ThisWeek->value);
        $period = DashboardDateRangeEnum::tryFrom(is_string($periodValue) ? $periodValue : '')
            ?? DashboardDateRangeEnum::ThisWeek;
        $siteValue = data_get($filters, 'site_id');
        $siteId = is_numeric($siteValue) && (int) $siteValue > 0 ? (int) $siteValue : null;
        $languageValue = data_get($filters, 'language');
        $language = is_string($languageValue) && $languageValue !== '' ? $languageValue : null;

        return new self(
            period: $period,
            date: is_string(data_get($filters, 'date')) ? data_get($filters, 'date') : null,
            siteId: $siteId,
            language: $language,
            refresh: (bool) data_get($filters, 'refresh', false),
            localFilters: is_array(data_get($filters, 'localFilters')) ? data_get($filters, 'localFilters') : [],
        );
    }
}
