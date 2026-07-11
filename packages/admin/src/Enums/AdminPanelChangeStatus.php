<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum AdminPanelChangeStatus: string
{
    case Applied = 'applied';
    case AlreadyApplied = 'already_applied';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case Manual = 'manual';
}
