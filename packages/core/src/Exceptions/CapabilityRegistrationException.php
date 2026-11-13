<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use Capell\Core\Enums\CapabilityContract;
use RuntimeException;

final class CapabilityRegistrationException extends RuntimeException
{
    public static function frozen(CapabilityContract $capability): self
    {
        return new self(sprintf(
            'The [%s] capability cannot be provided after boot. Provide it while your package service provider boots.',
            $capability->value,
        ));
    }

    public static function alreadyProvided(CapabilityContract $capability, string $existing, string $provider): self
    {
        return new self(sprintf(
            'The [%s] capability is already provided by [%s]; only one package may provide it, so [%s] was refused.',
            $capability->value,
            $existing,
            $provider,
        ));
    }

    public static function wrongType(string $implementation, string $contract): self
    {
        return new self(sprintf('The container resolved [%s] to an object that does not implement %s.', $implementation, $contract));
    }
}
