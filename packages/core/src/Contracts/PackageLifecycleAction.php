<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

use Capell\Core\Data\PackageData;

interface PackageLifecycleAction
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(PackageData $package, array $arguments = [], ?ProgressReporter $reporter = null): void;
}
