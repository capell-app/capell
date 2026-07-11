<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Blade;

use Capell\Frontend\Contracts\FrontendContextReader;

final class WireNavigateDirective
{
    public function compile(): string
    {
        return '<?php
            $capellRuntimeManifest = app()->bound(' . FrontendContextReader::class . '::class)
                ? resolve(' . FrontendContextReader::class . '::class)->getFrontendData(\'runtimeManifest\')
                : null;

            if (($capellRuntimeManifest?->usesWireNavigate ?? false) === true) {
                echo \' wire:navigate\';
            }
        ?>';
    }
}
