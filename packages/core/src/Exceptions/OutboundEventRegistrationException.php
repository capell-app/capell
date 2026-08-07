<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use LogicException;
use Spatie\LaravelData\Data;

/**
 * Thrown when a package contributes an invalid outbound event definition.
 *
 * These are all programmer errors detectable at boot, not bad user input, hence
 * {@see LogicException}: a malformed name, a non-positive version, a missing
 * description or owner package, a payload class that isn't a {@see Data}
 * object, a duplicate name, or a registration attempted after the registry
 * froze.
 */
final class OutboundEventRegistrationException extends LogicException
{
    public static function frozen(string $name): self
    {
        return new self(sprintf(
            'Outbound event [%s] cannot be registered after boot. Register events from bootInstalledPackage() via $this->surface()->outboundEvent().',
            $name,
        ));
    }

    public static function malformedName(string $name): self
    {
        return new self(sprintf(
            'Outbound event name [%s] must use the vendor-package.event-name format.',
            $name,
        ));
    }

    public static function invalidVersion(string $name): self
    {
        return new self(sprintf(
            'Outbound event [%s] versions must be positive integers.',
            $name,
        ));
    }

    public static function missingDescriptionOrOwnerPackage(string $name): self
    {
        return new self(sprintf(
            'Outbound event [%s] must name a description and the package that owns it.',
            $name,
        ));
    }

    public static function invalidPayloadClass(string $name, string $payloadClass): self
    {
        return new self(sprintf(
            'Outbound event [%s] payload [%s] must extend [%s].',
            $name,
            $payloadClass,
            Data::class,
        ));
    }

    public static function duplicateName(string $name): self
    {
        return new self(sprintf(
            'Outbound event [%s] is already registered.',
            $name,
        ));
    }
}
