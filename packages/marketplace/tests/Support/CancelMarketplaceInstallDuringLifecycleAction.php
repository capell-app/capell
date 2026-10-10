<?php

declare(strict_types=1);

namespace Capell\Marketplace\Tests\Support;

use Capell\Core\Contracts\PackageLifecycleAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Capell\Marketplace\Actions\CancelMarketplaceInstallAttemptAction;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Override;
use RuntimeException;

final class CancelMarketplaceInstallDuringLifecycleAction implements PackageLifecycleAction
{
    public static ?int $attemptId = null;

    #[Override]
    public function handle(
        PackageData $package,
        array $arguments = [],
        ?ProgressReporter $reporter = null,
    ): void {
        throw_if(self::$attemptId === null, RuntimeException::class, 'The late-cancellation attempt was not configured.');

        $attempt = MarketplaceInstallAttempt::query()->findOrFail(self::$attemptId);

        CancelMarketplaceInstallAttemptAction::run($attempt);
        $reporter?->report('cancellation requested during lifecycle');
    }
}
