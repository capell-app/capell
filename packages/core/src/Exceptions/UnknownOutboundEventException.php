<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;

/**
 * Thrown when an outbound event name cannot be resolved to a registered
 * definition, or when a payload does not match its declared class.
 *
 * Resolution is fail-closed: publishing an unregistered event surfaces a
 * package bug at publish time rather than silently dropping the event, and
 * `capell:extension-audit` catches undeclared publishes statically.
 */
final class UnknownOutboundEventException extends RuntimeException
{
    public static function forName(string $name): self
    {
        return new self(sprintf('Outbound event [%s] is not registered.', $name));
    }

    public static function forPayloadMismatch(string $eventName, string $expectedClass, string $givenClass): self
    {
        return new self(sprintf(
            'Outbound event [%s] expects payload [%s], given [%s].',
            $eventName,
            $expectedClass,
            $givenClass,
        ));
    }
}
