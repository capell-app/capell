<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum AlertTypeEnum: string
{
    case Danger = 'danger';

    case Gray = 'gray';

    case Info = 'info';

    case Success = 'success';

    case Warning = 'warning';
}
