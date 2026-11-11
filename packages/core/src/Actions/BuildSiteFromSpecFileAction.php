<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Site;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildSiteFromSpecFileAction
{
    use AsFake;
    use AsObject;

    /** @param list<string> $keepPublished */
    public function handle(string $path, bool $repairPrivate = false, array $keepPublished = [], bool $auditPrivate = false): Site
    {
        return BuildCapellSiteFromSpecAction::run(ReadSiteSpecFileAction::run($path), $repairPrivate, $keepPublished, $auditPrivate);
    }
}
