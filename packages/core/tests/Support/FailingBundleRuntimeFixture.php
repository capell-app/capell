<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Contracts\PackageLifecycleAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Override;
use RuntimeException;

final class FailingBundleRuntimeFixture implements PackageLifecycleAction
{
    #[Override]
    public function handle(PackageData $package, array $arguments = [], ?ProgressReporter $reporter = null): void
    {
        throw new RuntimeException('member failed');
    }
}
