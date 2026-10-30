<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions\Backup;

use Capell\Core\Data\Backup\ScratchRestoreFailureData;
use RuntimeException;
use Throwable;

final class ScratchRestoreFailedException extends RuntimeException
{
    public function __construct(string $message, public readonly ScratchRestoreFailureData $failure, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
