<?php

declare(strict_types=1);

namespace Capell\Core\Actions\FirstRun;

use Capell\Core\Support\FirstRun\SitePublicationMilestones;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Records the first time a site went live. The first value is kept, so
 * unpublishing and republishing a home page never resets the milestone.
 */
final class RecordSitePublishedAction
{
    use AsFake;
    use AsObject;

    /** @return bool whether this call recorded the milestone */
    public function handle(int $siteId, ?CarbonImmutable $at = null): bool
    {
        return resolve(SitePublicationMilestones::class)->record($siteId, $at ?? CarbonImmutable::now());
    }
}
