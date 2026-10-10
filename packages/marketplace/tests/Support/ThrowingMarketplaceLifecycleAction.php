<?php

declare(strict_types=1);

namespace Capell\Marketplace\Tests\Support;

use Capell\Core\Contracts\PackageLifecycleAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Override;
use RuntimeException;

final class ThrowingMarketplaceLifecycleAction implements PackageLifecycleAction
{
    #[Override]
    public function handle(
        PackageData $package,
        array $arguments = [],
        ?ProgressReporter $reporter = null,
    ): void {
        throw new RuntimeException('Lifecycle action refused this install.');
    }
}
