<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Upgrade;

enum UpgradeRunResult: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case NoOp = 'no_op';
}
