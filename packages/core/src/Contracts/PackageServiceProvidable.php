<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

use Capell\Core\Enums\PackageTypeEnum;

interface PackageServiceProvidable
{
    public static function getName(): string;

    public static function getType(): PackageTypeEnum;
}
