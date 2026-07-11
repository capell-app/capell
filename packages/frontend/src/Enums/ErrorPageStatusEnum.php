<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

use Filament\Support\Contracts\HasLabel;

enum ErrorPageStatusEnum: string implements HasLabel
{
    case Unauthorized = '401';

    case PaymentRequired = '402';

    case Forbidden = '403';

    case NotFound = '404';

    case PageExpired = '419';

    case TooManyRequests = '429';

    case ServerError = '500';

    case ServiceUnavailable = '503';

    /**
     * @return array<int, self>
     */
    public static function statuses(): array
    {
        return self::cases();
    }

    /**
     * @return array<int, string>
     */
    public static function statusValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Unauthorized => '401 — Unauthorized',
            self::PaymentRequired => '402 — Payment required',
            self::Forbidden => '403 — Forbidden',
            self::NotFound => '404 — Not found',
            self::PageExpired => '419 — Page expired',
            self::TooManyRequests => '429 — Too many requests',
            self::ServerError => '500 — Server error',
            self::ServiceUnavailable => '503 — Service unavailable',
        };
    }
}
