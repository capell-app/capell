<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum InstallPackageLicenceState: string
{
    case Free = 'free';
    case Required = 'required';
    case Included = 'included';
    case Unknown = 'unknown';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return (string) match ($this) {
            self::Free => __('capell-core::install.licence.free'),
            self::Required => __('capell-core::install.licence.required'),
            self::Included => __('capell-core::install.licence.included'),
            self::Unknown => __('capell-core::install.licence.unknown'),
            self::Unavailable => __('capell-core::install.licence.unavailable'),
        };
    }
}
