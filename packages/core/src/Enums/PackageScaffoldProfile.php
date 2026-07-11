<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum PackageScaffoldProfile: string
{
    case Minimal = 'minimal';
    case Full = 'full';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $profile): string => $profile->value,
            self::cases(),
        );
    }
}
