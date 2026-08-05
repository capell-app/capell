<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * One package, one operation in flight.
 *
 * Shared by installs and updates because the constraint is about the package on
 * disk, not about which of the two put it there: queueing an update for a
 * package that is mid-install would have two jobs contending for the same
 * Composer lock over the same vendor directory, and the second one would be
 * reasoning about a composer.json the first is still rewriting.
 */
final class AssertNoActiveMarketplaceOperationAction
{
    use AsFake;
    use AsObject;

    public static function fail(string $composerName): never
    {
        throw ValidationException::withMessages([
            'composer_name' => __('capell-marketplace::marketplace.operations.duplicate_active', [
                'package' => $composerName,
            ]),
        ]);
    }

    public function handle(string $composerName): void
    {
        $active = MarketplaceInstallAttempt::query()
            ->where('composer_name', $composerName)
            ->whereIn('status', array_map(
                static fn (MarketplaceInstallIntentStatus $status): string => $status->value,
                [
                    MarketplaceInstallIntentStatus::Queued,
                    MarketplaceInstallIntentStatus::Running,
                    MarketplaceInstallIntentStatus::CancelRequested,
                ],
            ))
            ->exists();

        if (! $active) {
            return;
        }

        self::fail($composerName);
    }
}
