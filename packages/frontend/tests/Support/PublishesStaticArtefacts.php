<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Support;

use Capell\Core\Actions\PublicRead\PublishPublicArtefactAction;
use Capell\Core\Data\PublicRead\PublicReadStampData;
use Capell\Core\Support\PublicRead\PublicArtefactPermit;
use Closure;

/**
 * Writes test fixtures to a public artefact store the only way production
 * can: through PublishPublicArtefactAction, with no package restricting reads.
 */
final class PublishesStaticArtefacts
{
    /** @param Closure(PublicArtefactPermit): mixed $write */
    public static function write(Closure $write, ?int $siteId = null): bool
    {
        return PublishPublicArtefactAction::run($siteId, new PublicReadStampData(null, null), [], static function (PublicArtefactPermit $permit) use ($write): bool {
            $write($permit);

            return true;
        });
    }
}
