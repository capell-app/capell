<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum PageOrderEnum: string
{
    case Default = 'default';

    case Latest = 'latest';

    case Oldest = 'oldest';

    case Alphabetical = 'alphabetical';
}
