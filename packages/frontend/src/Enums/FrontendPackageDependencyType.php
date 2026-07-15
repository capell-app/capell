<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

enum FrontendPackageDependencyType: string
{
    case Runtime = 'runtime';
    case Development = 'development';
}
