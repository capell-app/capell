<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class PhoneHomeAction
{
    use AsFake;
    use AsObject;

    public function handle(): bool
    {
        return RunMarketplaceHeartbeatAction::run()->successful;
    }
}
