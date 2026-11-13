<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\FirstRun;

use Capell\Core\Data\FirstRun\GuidedSetupInputData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;

/**
 * A theme opts in through frontend.setupPreviewNavigationRenderer on its definition.
 * The renderer hydrates its real public navigation DTOs from unsaved input, without
 * models, writes or authoring state. Null requests the generic preview navigation.
 */
interface GuidedSetupPreviewNavigationRenderer
{
    public function render(string $view, CapellSiteSpecData $spec, GuidedSetupInputData $input): ?string;
}
