<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Diagnostics;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class RegistrySourceData extends Data
{
    /**
     * @param  Collection<int, RegistryFlowStepData>|DataCollection<int, RegistryFlowStepData>  $flow
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $kind,
        public ?string $class,
        public ?string $view,
        public ?string $path,
        public string $sourcePackage,
        public string $sourceMode,
        public ?string $cachePath,
        public ?string $statePath,
        public Collection|DataCollection $flow,
    ) {}
}
