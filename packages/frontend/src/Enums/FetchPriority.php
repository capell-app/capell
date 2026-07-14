<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

enum FetchPriority: string
{
    case High = 'high';
    case Low = 'low';
    case Auto = 'auto';
}
