<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum SetupHealthEnum: string
{
    case Green = 'green';
    case Amber = 'amber';
    case Red = 'red';
}
