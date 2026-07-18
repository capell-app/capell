<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

final class UpgradeStepResult extends Data
{
    public function __construct(
        public readonly string $stepId,
        public readonly string $label,
        public readonly string $status,
        public readonly int $durationMs,
        public readonly ?string $output = null,
    ) {}
}
