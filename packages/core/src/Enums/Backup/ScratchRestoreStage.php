<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Backup;

enum ScratchRestoreStage: string
{
    case Media = 'media';
    case Doctor = 'doctor';
}
