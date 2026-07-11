<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum BlueprintGroupEnum: string
{
    case Default = 'default';

    case System = 'system';

    case Results = 'results';
}
