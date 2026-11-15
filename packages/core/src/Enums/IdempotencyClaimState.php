<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum IdempotencyClaimState: string
{
    /** This caller holds the key and should perform the operation. */
    case Acquired = 'acquired';

    /** Another caller holds the key and has not finished yet. */
    case InProgress = 'in_progress';

    /** The operation already finished; its stored result is replayed. */
    case Completed = 'completed';
}
