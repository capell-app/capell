<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

/**
 * The one description of the worker this installation needs.
 *
 * Readiness remediation and the operations page both have to tell an operator
 * how to start it, and the useful form of that is the command with this
 * installation's own connection and queue already in it — not a sentence asking
 * them to work it out.
 */
final class MarketplaceQueueWorkerCommand
{
    public static function connectionName(): string
    {
        return (string) config('capell-marketplace.marketplace.operations_queue_connection', 'database');
    }

    public static function queueName(): string
    {
        return (string) config('capell-marketplace.marketplace.operations_queue', 'capell-marketplace');
    }

    public static function forInstallation(): string
    {
        return sprintf(
            'php artisan queue:work %s --queue=%s',
            self::connectionName(),
            self::queueName(),
        );
    }

    /**
     * A synchronous connection has no worker to start: the install runs inside
     * the request that asked for it. Degraded rather than broken, so callers
     * report it as a warning.
     */
    public static function isSynchronous(): bool
    {
        return config('queue.connections.' . self::connectionName() . '.driver') === 'sync';
    }
}
