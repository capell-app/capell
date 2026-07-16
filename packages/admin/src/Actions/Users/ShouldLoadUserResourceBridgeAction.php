<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Users;

use Capell\Admin\Actions\Bridges\ShouldLoadAdminBridgeAction;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class ShouldLoadUserResourceBridgeAction
{
    use AsFake;
    use AsObject;

    public function handle(string $adminSetting, bool $packageEnabled, ?string $packageName = null): bool
    {
        return ShouldLoadAdminBridgeAction::run($adminSetting, $packageEnabled, $packageName);
    }
}
