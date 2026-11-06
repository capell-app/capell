<?php

declare(strict_types=1);

namespace Capell\Core\Listeners;

use Capell\Core\Actions\PublicRead\WithdrawPublicReadProjectionsAction;
use Capell\Core\Events\PublicReadEligibilityChanged;

/**
 * Runs every registered public-read withdrawal step for the change, durably:
 * a step that fails stays owed and is retried by the scheduler.
 */
final class WithdrawPublicReadProjectionsOnEligibilityChanged
{
    public function handle(PublicReadEligibilityChanged $event): void
    {
        WithdrawPublicReadProjectionsAction::run($event->revision, $event->siteId);
    }
}
