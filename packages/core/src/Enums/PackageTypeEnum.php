<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum PackageTypeEnum: string
{
    case Package = 'package';

    case Plugin = 'plugin';

    case Theme = 'theme';

    case Integration = 'integration';
}
