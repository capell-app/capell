<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Contracts\MarketplaceRuntimeRefresher;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\ArtisanMarketplaceRuntimeRefresher;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Push a completed install out to the processes that are still running the code
 * from before it, and say plainly which of them could not be reached.
 *
 * The install job runs in one worker process. Every other process serving the
 * application — the other Octane workers, the FPM pool, the other nodes — is
 * still holding the old autoloader, opcode cache, and container. Silence here
 * reads as "the install is live everywhere", which on anything but a plain
 * single-process host is untrue.
 *
 * So the honest options are narrow. On a single node this refreshes what it can
 * and names what it still cannot. On several nodes it refreshes nothing at all,
 * because a refresh performed here would only ever reach this one, and reporting
 * a partial refresh as a refresh is the failure this exists to prevent.
 */
final class PropagateMarketplaceRuntimeStateAction
{
    use AsFake;
    use AsObject;

    /**
     * @return string|null A line for the completion notification, or null when
     *                     nothing outside this process needed telling.
     */
    public function handle(MarketplaceInstallAttempt $attempt): ?string
    {
        $this->invalidateOpcache();

        // A declared topology, not a detected one. capell.multi_node is what the
        // operator said about this installation, it defaults to false, and
        // nothing here can prove otherwise from inside a single PHP process.
        // An operator running several nodes who never set CAPELL_MULTI_NODE
        // therefore gets the single-node treatment — auto-invoke and a
        // single-node notice — which is why that notice says "this node" rather
        // than claiming the installation as a whole is refreshed. The whole
        // system reads the flag the same way; the wording is what has to stay
        // honest about it.
        $multiNode = config('capell.multi_node', false) === true;

        if ($multiNode) {
            return $this->requireManualRefresh($attempt, 'runtime_refresh_required_multi_node');
        }

        if (! $this->longLivedRuntimeDetected()) {
            return null;
        }

        return $this->refresh()
            ? $this->recordNotice(
                $attempt,
                'timeline_runtime_refresh_invoked',
                'runtime_refresh_invoked',
                MarketplaceInstallAttemptEventLevel::Info,
            )
            : $this->requireManualRefresh($attempt, 'runtime_refresh_required_single_node');
    }

    /**
     * Octane keeps a booted application in memory per worker, so a newly
     * installed extension's providers do not exist in any worker but this one
     * until they restart.
     */
    private function longLivedRuntimeDetected(): bool
    {
        $server = config('octane.server');

        return is_string($server) && $server !== '';
    }

    private function refresh(): bool
    {
        try {
            return resolve(MarketplaceRuntimeRefresher::class)->refresh();
        } catch (Throwable $throwable) {
            // The package is installed and the attempt has already succeeded. A
            // refresh that failed is worth reporting and worth telling the
            // operator about, but it is not worth retracting a good install.
            report($throwable);

            return false;
        }
    }

    /**
     * A reset rather than a targeted invalidate: the install changed the
     * autoloader maps and the package manifest as well as adding files, and the
     * files it added were never cached to begin with.
     *
     * function_exists() covers only the absent-or-disabled cases. It does not
     * cover opcache.restrict_api: when the calling script sits outside the
     * configured prefix, opcache_reset() exists, is callable, and raises an
     * E_WARNING — which Laravel's error handler turns into an ErrorException.
     * A restricted host must not be able to make a completed install look like
     * a failed one, so the warning is suppressed and the reset simply does not
     * happen. Every one of these outcomes is a refresh that did not occur, not
     * an install that did not.
     */
    private function invalidateOpcache(): void
    {
        if (! function_exists('opcache_reset')) {
            return;
        }

        // @ zeroes error_reporting() for this expression, and Laravel's handler
        // gates on it before constructing an ErrorException, so there is nothing
        // to catch.
        @opcache_reset();
    }

    private function requireManualRefresh(MarketplaceInstallAttempt $attempt, string $noticeKey): string
    {
        return $this->recordNotice(
            $attempt,
            'timeline_runtime_refresh_required',
            $noticeKey,
            MarketplaceInstallAttemptEventLevel::Warning,
        );
    }

    private function recordNotice(
        MarketplaceInstallAttempt $attempt,
        string $timelineKey,
        string $noticeKey,
        MarketplaceInstallAttemptEventLevel $level,
    ): string {
        RecordMarketplaceInstallAttemptEventAction::run(
            attempt: $attempt,
            level: $level,
            message: (string) __('capell-marketplace::marketplace.operations.' . $timelineKey),
            stage: MarketplaceInstallFailureStage::Notification,
            context: ['command' => ArtisanMarketplaceRuntimeRefresher::COMMAND],
        );

        return (string) __('capell-marketplace::marketplace.operations.' . $noticeKey);
    }
}
