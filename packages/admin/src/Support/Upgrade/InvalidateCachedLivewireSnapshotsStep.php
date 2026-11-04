<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Upgrade;

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;
use Illuminate\Support\Facades\Artisan;
use Override;

/**
 * Livewire updates now require the snapshot to carry its signed rendering route. Public pages stored
 * by the HTML cache before this release carry snapshots without it, so their components would answer
 * "page expired"; the upgrade marks that stored HTML stale so every page is regenerated.
 */
final class InvalidateCachedLivewireSnapshotsStep extends AbstractUpgradeStep
{
    private const string HTML_CACHE_CLEAR = 'capell:html-cache:clear';

    #[Override]
    public function id(): string
    {
        return 'capell-admin.livewire-snapshot-rendering-routes';
    }

    #[Override]
    public function label(): string
    {
        return __('capell-admin::message.upgrade_livewire_snapshot_routes');
    }

    #[Override]
    public function package(): string
    {
        return 'capell-app/admin';
    }

    #[Override]
    public function run(UpgradeContext $context): bool
    {
        // Without the HTML cache package no public HTML is stored, so there is nothing to regenerate.
        if (! array_key_exists(self::HTML_CACHE_CLEAR, Artisan::all())) {
            return true;
        }

        return Artisan::call(self::HTML_CACHE_CLEAR) === 0;
    }
}
