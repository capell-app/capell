<?php

declare(strict_types=1);

namespace Capell\Core\Actions\PublicRead;

use Capell\Core\Contracts\PublicRead\PublicArtefactFence;
use Capell\Core\Data\PublicRead\PublicReadStampData;
use Capell\Core\Models\PageUrl;
use Capell\Core\Support\PublicRead\PublicArtefactPermit;
use Capell\Core\Support\PublicRead\PublicReadEligibilityState;
use Carbon\CarbonImmutable;
use Closure;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * The only way an out-of-band public artefact is written: a copy that is
 * served without Laravel checking the public-read rules again, such as an
 * HTML cache disk copy or a static export.
 *
 * The write runs under the store's own lease-free lock, the lock every
 * withdrawal from that store holds. Under it the eligibility stamp is read
 * afresh, and the write is refused when:
 *
 * - the rules cannot be read, or the copy could not be stamped when built;
 * - the revision differs from the one the copy was built under;
 * - either stamp has a finite valid_until (time-bounded rules are served only
 *   by Laravel, which checks them on every request);
 * - any page the copy shows is no longer publicly readable.
 *
 * A render that started under the old rules therefore either writes before
 * the withdrawal (and is removed by it) or fails this check. The writer is
 * handed a permit bound to that store, revoked when it returns.
 *
 * @experimental
 *
 * @method static bool run(PublicArtefactFence $store, int|null $siteId, PublicReadStampData|null $builtUnder, list<PageUrl> $pageUrls, Closure $write)
 */
final class PublishPublicArtefactAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly PublicReadEligibilityState $state,
    ) {}

    /**
     * Returns whether the artefact was written.
     *
     * @param  int|null  $siteId  the site whose pages the artefact shows (null: any site)
     * @param  list<PageUrl>  $pageUrls  the pages the artefact shows, checked for public readability
     * @param  Closure(PublicArtefactPermit): bool  $write
     */
    public function handle(PublicArtefactFence $store, ?int $siteId, ?PublicReadStampData $builtUnder, array $pageUrls, Closure $write): bool
    {
        return $store->exclusive(fn (): bool => $this->allowed($siteId, $builtUnder, $pageUrls) && $this->withPermit($store, $write));
    }

    /**
     * @param  list<PageUrl>  $pageUrls
     */
    private function allowed(?int $siteId, ?PublicReadStampData $builtUnder, array $pageUrls): bool
    {
        if (! $builtUnder instanceof PublicReadStampData || $builtUnder->validUntil instanceof CarbonImmutable) {
            return false;
        }

        $fresh = $this->state->freshStamp($siteId);

        if (! $fresh instanceof PublicReadStampData || $fresh->validUntil instanceof CarbonImmutable || $fresh->revision !== $builtUnder->revision) {
            return false;
        }

        if ($pageUrls === []) {
            return true;
        }

        return ! in_array(false, PageUrlsArePubliclyReadableAction::run($pageUrls), true);
    }

    /**
     * Runs $callback with a permit for $store that is active only while it runs.
     *
     * @param  Closure(PublicArtefactPermit): bool  $callback
     */
    private function withPermit(PublicArtefactFence $store, Closure $callback): bool
    {
        $active = true;
        $issue = Closure::bind(
            static fn (Closure $isActive, string $identity): PublicArtefactPermit => new PublicArtefactPermit($isActive, $identity),
            null,
            PublicArtefactPermit::class,
        );
        $permit = $issue(static function () use (&$active): bool {
            return $active;
        }, $store->fenceIdentity());

        try {
            return $callback($permit);
        } finally {
            $active = false;
        }
    }
}
