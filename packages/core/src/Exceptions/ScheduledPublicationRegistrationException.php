<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use Capell\Core\Contracts\Publishing\SchedulesPublication;
use RuntimeException;

final class ScheduledPublicationRegistrationException extends RuntimeException
{
    public static function frozen(string $modelClass): self
    {
        return new self(sprintf(
            '[%s] cannot be registered for scheduled publication after boot. Register it while your package service provider boots.',
            $modelClass,
        ));
    }

    public static function invalidModel(string $modelClass): self
    {
        return new self(sprintf(
            '[%s] cannot be registered for scheduled publication: it must be an Eloquent model implementing %s.',
            $modelClass,
            SchedulesPublication::class,
        ));
    }
}
