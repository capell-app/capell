<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Support;

use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** Renders the notifications component with everything the code under test has sent so far. */
final class SentNotifications
{
    public static function component(): Testable
    {
        // Newer Filament releases hold sent notifications in a scoped mailbox until a response publishes them.
        if (class_exists(NotificationMailbox::class)) {
            resolve(NotificationMailbox::class)->flush();
        }

        return Livewire::test(Notifications::class)->call('pullNotificationsFromSession');
    }
}
