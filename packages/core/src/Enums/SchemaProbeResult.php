<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum SchemaProbeResult: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Failed = 'failed';

    public function exists(): bool
    {
        return $this === self::Present;
    }
}
