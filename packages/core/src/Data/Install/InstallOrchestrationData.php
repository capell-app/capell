<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Spatie\LaravelData\Data;

final class InstallOrchestrationData extends Data
{
    public function __construct(
        public readonly bool $outputPlan,
        public readonly bool $runNpmBuild,
        public readonly bool $removeInstaller,
        /** @var array<string> */
        public readonly array $cachesToClear,
    ) {}
}
