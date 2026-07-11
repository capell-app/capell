<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Diagnostics;

use Spatie\LaravelData\Data;

class RegistryFlowStepData extends Data
{
    public function __construct(
        public string $label,
        public string $value,
        public string $status,
    ) {}
}
