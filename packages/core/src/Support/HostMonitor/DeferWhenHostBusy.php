<?php

declare(strict_types=1);

namespace Capell\Core\Support\HostMonitor;

use Capell\Core\Contracts\Health\HostBusyGate;
use Illuminate\Console\Scheduling\Event;

/** Marks a scheduled task as one that can wait while this server is critically busy. */
final class DeferWhenHostBusy
{
    public static function apply(Event $event, string $name): Event
    {
        return $event->skip(static fn (): bool => resolve(HostBusyGate::class)->shouldDefer($name));
    }
}
