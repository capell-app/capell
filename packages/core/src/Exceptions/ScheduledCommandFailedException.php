<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;

final class ScheduledCommandFailedException extends RuntimeException
{
    public static function forExitCode(string $command, int $exitCode): self
    {
        return new self(sprintf('The scheduled command [%s] exited with code %d.', $command, $exitCode));
    }
}
