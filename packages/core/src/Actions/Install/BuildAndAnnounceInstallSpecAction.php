<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Actions\BuildSiteFromSpecFileAction;
use Capell\Core\Events\CapellInstalled;
use Illuminate\Support\Facades\Event;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildAndAnnounceInstallSpecAction
{
    use AsFake;
    use AsObject;

    public function handle(?string $specOption, bool $seededDefaults): void
    {
        if ($specOption === null || $specOption === '') {
            return;
        }

        $resolvedPath = realpath($specOption);
        $specPath = $resolvedPath === false ? $specOption : $resolvedPath;

        BuildSiteFromSpecFileAction::run($specPath);
        Event::dispatch(new CapellInstalled($specPath, $seededDefaults));
    }
}
