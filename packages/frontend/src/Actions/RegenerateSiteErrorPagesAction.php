<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\StaticErrorPageStore;
use Capell\Frontend\Support\Error\ErrorPageRegenerationFingerprint;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Regenerate the static error pages for a single site.
 *
 * Mirrors the html-cache targeted-invalidation flow: dispatched after the
 * response (or synchronously in console/tests) when an error-page-relevant
 * model changes. Never throws — failures are logged and swallowed so a model
 * save is never blocked by error-page regeneration.
 *
 * Change-driven callers (the model observer) pass `force: false`, which renders
 * only when the site's error-page inputs actually differ from the ones the
 * current artefacts were built from. Public traffic that writes rows unrelated
 * to the rendered output — a 404 flood recording not-found visits, for instance
 * — then costs a few indexed lookups per hit instead of a full re-render of
 * every status page for every domain. Explicit callers (console commands,
 * package installs, deploys) keep the default `force: true`, because code,
 * theme, or translation-file changes are invisible to the fingerprint.
 *
 * @method static void run(int $siteId, bool $force = true)
 */
class RegenerateSiteErrorPagesAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    public function __construct(private readonly ErrorPageRegenerationFingerprint $fingerprint) {}

    public function handle(int $siteId, bool $force = true): void
    {
        if (! app()->bound(StaticErrorPageStore::class)) {
            return;
        }

        $site = Site::query()
            ->whereKey($siteId)
            ->with(['language', 'siteDomains.language', 'theme', 'translations', 'logo'])
            ->first();

        if (! $site instanceof Site || ! $site->isEnabled()) {
            return;
        }

        $lock = $this->lock($siteId);

        try {
            // Several web workers can observe the same change at once. The
            // loser waits briefly rather than skipping, so a genuine change is
            // never dropped: it re-reads the fingerprint the winner stored and
            // regenerates only if its own change is still unaccounted for.
            if ($lock !== null && ! $lock->block(5)) {
                return;
            }
        } catch (LockTimeoutException) {
            return;
        } catch (Throwable) {
            $lock = null;
        }

        try {
            $currentFingerprint = $this->currentFingerprint($site);

            if (! $force
                && $currentFingerprint !== null
                && $currentFingerprint === $this->fingerprint->stored($siteId)
                && $this->fingerprint->hasArtefacts($siteId)
            ) {
                return;
            }

            GenerateErrorPageCacheAction::run($site);

            if ($currentFingerprint !== null) {
                // Stored before the render started, so a change that lands
                // mid-render still leaves the site regenerating next time.
                $this->fingerprint->remember($siteId, $currentFingerprint);
            }
        } catch (Throwable $throwable) {
            $this->fingerprint->forget($siteId);

            Log::warning('capell: error page regeneration failed', [
                'site_id' => $siteId,
                'exception' => $throwable->getMessage(),
            ]);
        } finally {
            $lock?->release();
        }
    }

    private function currentFingerprint(Site $site): ?string
    {
        try {
            return $this->fingerprint->current($site);
        } catch (Throwable $throwable) {
            // A fingerprint that cannot be computed must never suppress or
            // break regeneration, so this falls back to always rendering — but
            // it says so at warning level, because that fallback silently
            // restores the per-hit re-render the gate exists to prevent. The
            // warning is rate-limited per site so the flood it reports cannot
            // become a log flood; with a non-persistent cache it degrades to
            // once per process, never to silence.
            if ($this->shouldWarnAboutFingerprint((int) $site->getKey())) {
                Log::warning('capell: error page regeneration fingerprint unavailable, falling back to unconditional rendering', [
                    'site_id' => (int) $site->getKey(),
                    'exception' => $throwable->getMessage(),
                ]);
            }

            return null;
        }
    }

    private function shouldWarnAboutFingerprint(int $siteId): bool
    {
        try {
            return Cache::add('capell.error-pages.fingerprint-warned:' . $siteId, true, 300);
        } catch (Throwable) {
            return true;
        }
    }

    private function lock(int $siteId): ?Lock
    {
        try {
            return Cache::lock('capell.error-pages.regenerate:' . $siteId, 120);
        } catch (Throwable) {
            return null;
        }
    }
}
