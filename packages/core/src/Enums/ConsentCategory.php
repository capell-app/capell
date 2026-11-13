<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

/**
 * The consent categories every Capell consent provider decides on. A purpose (a widget,
 * an embed, an integration) belongs to exactly one category. Essential is always allowed.
 */
enum ConsentCategory: string
{
    case Essential = 'essential';
    case Functional = 'functional';
    case Preferences = 'preferences';
    case Analytics = 'analytics';
    case Marketing = 'marketing';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $category): string => $category->value, self::cases());
    }
}
