<?php

declare(strict_types=1);

namespace Capell\Admin\Console\Commands;

use Capell\Admin\Actions\CheckForUpdatesAction;
use Capell\Admin\Actions\Upgrade\SendUpgradeNotificationEmailAction;
use Illuminate\Console\Command;

final class SendUpgradeSummaryNotificationCommand extends Command
{
    protected $signature = 'capell:admin-upgrade-summary-email';

    protected $description = 'Check for Capell updates and email the configured admin recipients when action is needed.';

    public function handle(): int
    {
        CheckForUpdatesAction::run();

        $sentCount = SendUpgradeNotificationEmailAction::run();

        $this->info(sprintf('Sent %d upgrade summary notification email(s).', $sentCount));

        return self::SUCCESS;
    }
}
