<?php

declare(strict_types=1);

use Capell\Admin\Data\Dashboard\DashboardFilterStateData;
use Capell\Admin\Enums\DashboardDateRangeEnum;

it('normalises dashboard filter values into typed state', function (): void {
    $state = DashboardFilterStateData::fromFilters([
        'date_range' => 'last_30_days',
        'date' => '2026-08-13',
        'site_id' => '7',
        'language' => 'en-GB',
        'refresh' => 1,
        'localFilters' => ['kind' => 'theme'],
    ]);

    expect($state->period)->toBe(DashboardDateRangeEnum::Last30Days)
        ->and($state->date)->toBe('2026-08-13')
        ->and($state->siteId)->toBe(7)
        ->and($state->language)->toBe('en-GB')
        ->and($state->refresh)->toBeTrue()
        ->and($state->localFilters)->toBe(['kind' => 'theme']);
});

it('uses safe defaults for invalid or absent dashboard filters', function (): void {
    $state = DashboardFilterStateData::fromFilters([
        'date_range' => 'unknown',
        'site_id' => 0,
        'language' => '',
        'date' => 42,
        'localFilters' => 'invalid',
    ]);

    expect($state->period)->toBe(DashboardDateRangeEnum::ThisWeek)
        ->and($state->date)->toBeNull()
        ->and($state->siteId)->toBeNull()
        ->and($state->language)->toBeNull()
        ->and($state->refresh)->toBeFalse()
        ->and($state->localFilters)->toBe([]);
});
