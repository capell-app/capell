<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum IdempotencyKeyStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
}
