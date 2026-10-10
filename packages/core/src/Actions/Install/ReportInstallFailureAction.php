<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Install\InstallSiteUrl;
use Illuminate\Support\Facades\Artisan;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ReportInstallFailureAction
{
    use AsFake;
    use AsObject;

    /** @param list<string> $completedSteps */
    public function handle(InstallInputData $input, array $completedSteps, string $failedStep, ProgressReporter $reporter): void
    {
        $reporter->error(__('capell-core::install.recovery.failed', ['step' => $failedStep]));
        $reporter->report(__('capell-core::install.recovery.completed', ['count' => count($completedSteps), 'steps' => implode(', ', $completedSteps)]));
        if ($failedStep === InstallPlan::STEP_REBUILD_RESOURCES) {
            $command = array_key_exists('capell:frontend-after-install', Artisan::all())
                ? 'php artisan capell:frontend-after-install --apply --no-interaction'
                : 'npm install && npm run build';
            $reporter->report(__('capell-core::install.recovery.assets', ['command' => $command]));

            return;
        }

        $urlOption = InstallSiteUrl::validationError($input->siteUrl) === null
            ? ' --url=' . escapeshellarg(InstallSiteUrl::publicUrl($input->siteUrl))
            : '';
        $command = 'php artisan capell:install --plan --no-interaction' . $urlOption
            . ' --packages=' . escapeshellarg(implode(',', array_unique([...$input->packages, ...$input->extraPackages])));
        $reporter->report(__('capell-core::install.recovery.inspect', ['command' => $command]));
    }
}
