<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Actions;

use Filament\Actions\Action;

final class RunMarketplaceHeartbeatAction
{
    public static function make(MarketplaceConnectionFormModel $connection): Action
    {
        return $connection->heartbeatAction();
    }
}
