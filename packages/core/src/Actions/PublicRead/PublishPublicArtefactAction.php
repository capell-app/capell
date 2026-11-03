<?php

declare(strict_types=1);

namespace Capell\Core\Actions\PublicRead;

use Capell\Core\Data\PublicRead\PublicReadStampData;
use Capell\Core\Models\PageUrl;
use Capell\Core\Support\PublicRead\PublicArtefactPermit;
use Capell\Core\Support\PublicRead\PublicArtefactPublicationLock;
use Capell\Core\Support\PublicRead\PublicReadEligibilityState;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * The only way an out-of-band public artefact is written: a copy that is
 * served without Laravel checking the public-read rules again, such as an
 * HTML cache disk copy or a static export.
 *
 * The write runs under the publication lock for its site, the same lock every
 * withdrawal of those artefacts holds. Under the lock the eligibility stamp is
 * read afresh, and the write is refused when:
 *
 * - the rules cannot be read, or the copy could not be stamped when built;
 * - the revision differs from the one the copy was built under;
 * - either stamp has a finite valid_until (time-bounded rules are served only
 *   by Laravel, which checks them on every request);
 * - any page the copy shows is no longer publicly readable.
 *
 * A render that started under the old rules therefore either writes before
 * the withdrawal (and is purged by it) or fails this check. The writer is
 * handed a permit that the artefact stores require, revoked when it returns.
 *
 * @experimental
 *
 * @method static bool run(int|null $siteId, PublicReadStampData|null $builtUnder, list<PageUrl> $pageUrls, Closure $write, Closure|null $retract = null)
 */
final class PublishPublicArtefactAction
{
    use AsFake;
    use AsObject;

    /** How long a writer waits for a withdrawal to finish before giving up its write. */
    public const int WAIT_SECONDS = 5;

    public function __construct(
        private readonly PublicArtefactPublicationLock $lock,
        private readonly PublicReadEligibilityState $state,
    ) {}

    /**
     * Returns whether the artefact was written.
     *
     * @param  list<PageUrl>  $pageUrls  the pages the artefact shows, checked for public readability
     * @param  Closure(PublicArtefactPermit): bool  $write
     * @param  (Closure(PublicArtefactPermit): void)|null  $retract  removes the written artefact if the lock was lost while writing
     */
    public function handle(?int $siteId, ?PublicReadStampData $builtUnder, array $pageUrls, Closure $write, ?Closure $retract = null): bool
    {
        try {
            return $this->lock->hold([$siteId], self::WAIT_SECONDS, function (Closure $stillHeld) use ($builtUnder, $pageUrls, $write, $retract): bool {
                if (! $this->allowed($builtUnder, $pageUrls)) {
                    return false;
                }

                $written = $this->withPermit($write);

                // A lease that ran out mid-write lets a withdrawal run before
                // the bytes landed; undo the write when the rules moved.
                if ($written && ! $stillHeld() && $retract instanceof Closure && ! $this->allowed($builtUnder, $pageUrls)) {
                    $this->withPermit(static function (PublicArtefactPermit $permit) use ($retract): bool {
                        $retract($permit);

                        return true;
                    });

                    return false;
                }

                return $written;
            });
        } catch (LockTimeoutException) {
            // A withdrawal is still running: this copy may already be stale.
            return false;
        }
    }

    /**
     * @param  list<PageUrl>  $pageUrls
     */
    private function allowed(?PublicReadStampData $builtUnder, array $pageUrls): bool
    {
        if (! $builtUnder instanceof PublicReadStampData || $builtUnder->validUntil instanceof CarbonImmutable) {
            return false;
        }

        $fresh = $this->state->freshStamp();

        if (! $fresh instanceof PublicReadStampData || $fresh->validUntil instanceof CarbonImmutable || $fresh->revision !== $builtUnder->revision) {
            return false;
        }

        if ($pageUrls === []) {
            return true;
        }

        return ! in_array(false, PageUrlsArePubliclyReadableAction::run($pageUrls), true);
    }

    /**
     * Runs $callback with a permit that is active only while it runs.
     *
     * @param  Closure(PublicArtefactPermit): bool  $callback
     */
    private function withPermit(Closure $callback): bool
    {
        $active = true;
        $issue = Closure::bind(
            static fn (Closure $isActive): PublicArtefactPermit => new PublicArtefactPermit($isActive),
            null,
            PublicArtefactPermit::class,
        );
        $permit = $issue(static function () use (&$active): bool {
            return $active;
        });

        try {
            return $callback($permit);
        } finally {
            $active = false;
        }
    }
}
