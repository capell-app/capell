<?php

declare(strict_types=1);

use Capell\Core\Enums\UrlParamTypeEnum;

it('coerces integers', function (): void {
    expect(UrlParamTypeEnum::Int->coerce('42'))->toBe(42)
        ->and(UrlParamTypeEnum::Int->coerce('-10'))->toBe(-10)
        ->and(UrlParamTypeEnum::Int->coerce('4.2'))->toBeNull()
        ->and(UrlParamTypeEnum::Int->coerce('abc'))->toBeNull();
});

it('coerces strings', function (): void {
    expect(UrlParamTypeEnum::String->coerce('slug-value'))->toBe('slug-value')
        ->and(UrlParamTypeEnum::String->coerce('0'))->toBe('0')
        ->and(UrlParamTypeEnum::String->coerce(''))->toBeNull();
});

it('coerces dates with calendar validation', function (): void {
    expect(UrlParamTypeEnum::Date->coerce('2026-03'))->toBe('2026-03')
        ->and(UrlParamTypeEnum::Date->coerce('2026-03-18'))->toBe('2026-03-18')
        ->and(UrlParamTypeEnum::Date->coerce('2026-13'))->toBeNull()
        ->and(UrlParamTypeEnum::Date->coerce('2026-02-30'))->toBeNull()
        ->and(UrlParamTypeEnum::Date->coerce('18-03-2026'))->toBeNull();
});

it('rejects unsupported blueprints when coercing by type', function (): void {
    expect(UrlParamTypeEnum::coerceByType('abc-123', 'uuid'))->toBeNull()
        ->and(UrlParamTypeEnum::coerceByType('9', UrlParamTypeEnum::Int->value))->toBe(9);
});
