<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum NavigationGroupPositionEnum: string
{
    case Start = 'start';
    case End = 'end';
    case Before = 'before';
    case After = 'after';
}
