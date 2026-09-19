<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Database;

enum DatabaseProvisioningResult: string
{
    case Created = 'created';
    case Ready = 'ready';
    case Unavailable = 'unavailable';

    public function isReady(): bool
    {
        return $this !== self::Unavailable;
    }
}
