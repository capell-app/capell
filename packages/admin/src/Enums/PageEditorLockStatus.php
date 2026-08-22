<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum PageEditorLockStatus: string
{
    case Available = 'available';
    case Conflict = 'conflict';
    case Owned = 'owned';
    case Unavailable = 'unavailable';
}
