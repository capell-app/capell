<?php

declare(strict_types=1);

namespace Capell\Marketplace\Console\Commands;

use Capell\Marketplace\Actions\CheckSiteLicenceAction;
use Capell\Marketplace\Data\SiteLicenceStatusData;
use Illuminate\Console\Command;

final class CheckSiteLicenceCommand extends Command
{
    protected $signature = 'capell:licence:check';

    protected $description = "Check this site's licence by sending its domain and licence key, and refresh the admin notice";

    public function handle(CheckSiteLicenceAction $check): int
    {
        $status = $check->handle();

        $this->components->info($status instanceof SiteLicenceStatusData
            ? (string) __('capell-marketplace::licence.command.state', ['state' => $status->state->value])
            : (string) __('capell-marketplace::licence.command.no_paid_packages'));

        return self::SUCCESS;
    }
}
