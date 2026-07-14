<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Capell\Frontend\Enums\FrontendPackageManager;
use Spatie\LaravelData\Data;

final class FrontendDependencyPlanData extends Data
{
    /**
     * @param  array<int, string>  $runtimeCommand
     * @param  array<int, string>  $developmentCommand
     * @param  array<string, array<string, mixed>>  $requirements
     * @param  array<int, array<string, mixed>>  $diagnostics
     */
    public function __construct(
        public readonly FrontendPackageManager $manager,
        public readonly array $runtimeCommand,
        public readonly array $developmentCommand,
        public readonly array $requirements,
        public readonly array $diagnostics,
    ) {}
}
