<?php

declare(strict_types=1);

namespace Capell\Core\Actions\PublicRead;

use Capell\Core\Events\PublicReadEligibilityChanged;
use Capell\Core\Support\PublicRead\PublicReadEligibilityRevision;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Advances the eligibility revision and announces it, so render data, sitemaps
 * and other stored projections stop serving what may now be restricted.
 *
 * @experimental
 *
 * @method static string|null run(string $source, ?int $siteId = null)
 */
final class RecordPublicReadEligibilityChangeAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly PublicReadEligibilityRevision $revision) {}

    /**
     * Returns the new revision, or null when it could not be stored (every
     * public read is then denied until it can be).
     */
    public function handle(string $source, ?int $siteId = null): ?string
    {
        $revision = $this->revision->advance();

        event(new PublicReadEligibilityChanged($revision, $source, $siteId));

        return $revision;
    }
}
