<?php

declare(strict_types=1);

namespace Capell\Installer\Data\InstallGuide;

use Spatie\LaravelData\Data;

class ApplyPatchesInputData extends Data
{
    /**
     * @param  array<string>  $patchIds
     */
    public function __construct(
        public array $patchIds,
    ) {}
}
