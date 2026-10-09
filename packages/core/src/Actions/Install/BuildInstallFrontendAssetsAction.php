<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Actions\RunNpmBuildAction;
use Capell\Core\Contracts\ProgressReporter;
use Illuminate\Support\Facades\Artisan;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildInstallFrontendAssetsAction
{
    use AsFake;
    use AsObject;

    public function handle(?ProgressReporter $reporter = null): void
    {
        // Frontend owns package-manager detection, dependencies and Vite integration.
        if (array_key_exists('capell:frontend-after-install', Artisan::all())) {
            RunArtisanCommandAction::run('capell:frontend-after-install', ['--apply' => true, '--no-interaction' => true], $reporter);

            return;
        }

        RunNpmBuildAction::run();
    }
}
