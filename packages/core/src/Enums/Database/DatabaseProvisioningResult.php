<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Database;

enum DatabaseProvisioningResult
{
    case Created;
    case Ready;
    case Unavailable;

    public function isReady(): bool
    {
        return $this !== self::Unavailable;
    }
}
