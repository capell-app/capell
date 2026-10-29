<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Core\Actions\RuntimeRefresh\RefreshConfigurationCacheAction;
use Capell\Core\Support\Patching\EnvFileEditor;
use Illuminate\Support\Env;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class EnableNewInstallationDashboardAction
{
    use AsFake;
    use AsObject;

    public function handle(bool $initialInstallation, ?string $environmentPath = null): bool
    {
        if (! $initialInstallation) {
            return false;
        }

        $path = $environmentPath ?? app()->environmentFilePath();
        if (! is_file($path) || Env::get('CAPELL_WORK_FIRST_DASHBOARD') !== null) {
            return false;
        }

        $editor = new EnvFileEditor($path);
        if ($editor->get('CAPELL_WORK_FIRST_DASHBOARD') !== null) {
            return false;
        }

        $configuration = RefreshConfigurationCacheAction::run(rebuild: false);
        if (! $configuration->passed) {
            throw new RuntimeException($configuration->message);
        }

        $editor->backup();
        $editor->set('CAPELL_WORK_FIRST_DASHBOARD', 'true')->save();

        return true;
    }
}
