<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Bridges;

use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Facades\CapellCore;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

class ShouldLoadAdminBridgeAction
{
    use AsAction;

    public function handle(string $adminSetting, bool $packageEnabled, ?string $packageName = null): bool
    {
        try {
            $settings = AdminSettings::instance();
        } catch (Throwable) {
            return false;
        }

        $adminSettingValue = data_get($settings, $adminSetting);
        $adminEnabled = is_bool($adminSettingValue) && $adminSettingValue;

        if (! $adminEnabled || ! $packageEnabled) {
            return false;
        }

        if ($packageName === null) {
            return true;
        }

        return CapellCore::isPackageInstalled($packageName);
    }
}
