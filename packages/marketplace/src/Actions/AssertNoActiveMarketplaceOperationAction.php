<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Whether this package already has an operation in flight.
     *
     * Public so that a surface deciding whether to *offer* an operation asks the
     * same question this action answers when it refuses one — an offer the
     * product will reject downstream is still an offer that should not have been
     * made.
     */
    public static function isActive(string $composerName): bool
    {
        return MarketplaceInstallAttempt::query()
            ->where(static function (Builder $query) use ($composerName): void {
                $query->where('composer_name', $composerName)
                    ->orWhereJsonContains('context->affected_package_names', $composerName);
            })
            ->whereIn('status', array_map(
                static fn (MarketplaceInstallIntentStatus $status): string => $status->value,
                [
                    MarketplaceInstallIntentStatus::Queued,
                    MarketplaceInstallIntentStatus::Running,
                    MarketplaceInstallIntentStatus::CancelRequested,
                ],
            ))
            ->exists();
    }

    public function handle(string $composerName): void
    {
        if (! self::isActive($composerName)) {
            return;
        }

        self::fail($composerName);
    }
}
