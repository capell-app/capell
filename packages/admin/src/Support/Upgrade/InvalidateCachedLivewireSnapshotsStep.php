<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Upgrade;

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Override;
use RuntimeException;

/**
 * Livewire updates now require the snapshot to carry its signed rendering route. Public pages stored by
 * the HTML cache before this release carry snapshots without it, so their components would answer "page
 * expired". The upgrade regenerates every stored page in this process, purging each from the edge cache
 * as it is regenerated, and fails with the commands that finish the refresh when it cannot complete.
 */
final class InvalidateCachedLivewireSnapshotsStep extends AbstractUpgradeStep
{
    public const string HTML_CACHE_CLEAR = 'capell:html-cache:clear';

    public const string HTML_CACHE_PROCESS_STALE = 'capell:html-cache:process-stale';

    public const string EDGE_PURGE_VERIFY = 'capell:html-cache:edge-purge:verify';

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
        $commands = Artisan::all();
        // Without the HTML cache package no public HTML is stored, so there is nothing to regenerate.
        if (! array_key_exists(self::HTML_CACHE_CLEAR, $commands)) {
            return true;
        }

        // Regenerated pages are purged from the edge as they are written; an edge that cannot be purged
        // would keep serving the old pages, so the refresh does not start.
        if (array_key_exists(self::EDGE_PURGE_VERIFY, $commands)) {
            [$exitCode, $output] = $this->call(self::EDGE_PURGE_VERIFY, []);
            if ($exitCode !== 0) {
                throw new RuntimeException(__('capell-admin::message.upgrade_livewire_snapshot_routes_edge_unready', [
                    'output' => $output,
                    'commands' => 'php artisan ' . self::EDGE_PURGE_VERIFY,
                ]));
            }
        }

        [$exitCode, $output] = $this->call(self::HTML_CACHE_CLEAR, ['--process' => true]);
        if ($exitCode !== 0) {
            throw new RuntimeException(__('capell-admin::message.upgrade_livewire_snapshot_routes_failed', [
                'output' => $output,
                'commands' => 'php artisan ' . self::HTML_CACHE_PROCESS_STALE . ' (repeat until it reports no failures), then php artisan ' . self::EDGE_PURGE_VERIFY,
            ]));
        }

        Log::info(__('capell-admin::message.upgrade_livewire_snapshot_routes_done'), ['output' => $output]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{0: int, 1: string}
     */
    private function call(string $command, array $parameters): array
    {
        $exitCode = Artisan::call($command, $parameters);

        return [$exitCode, trim(Artisan::output())];
    }
}
