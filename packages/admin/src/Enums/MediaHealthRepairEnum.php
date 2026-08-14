<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum MediaHealthRepairEnum: string
{
    case MarkDecorative = 'mark_decorative';

    case DeleteUnused = 'delete_unused';
}
