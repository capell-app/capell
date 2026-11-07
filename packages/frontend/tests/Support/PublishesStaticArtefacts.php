<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Support;

use Capell\Core\Actions\PublicRead\PublishPublicArtefactAction;
use Capell\Core\Data\PublicRead\PublicReadStampData;
use Capell\Core\Support\PublicRead\PublicArtefactPermit;
use Capell\Frontend\Contracts\StaticErrorPageStore;
use Capell\Frontend\Contracts\StaticMaintenancePageStore;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Closure;
use RuntimeException;

/**
 * Writes test fixtures to a public artefact store the only way production
 * can: through PublishPublicArtefactAction under the static store's lock,
 * with no package restricting reads.
 */
final class PublishesStaticArtefacts
{
    /** @param Closure(PublicArtefactPermit): mixed $write */
    public static function write(Closure $write, ?int $siteId = null): bool
    {
        return PublishPublicArtefactAction::run(resolve(StaticPageArtifactStore::class), $siteId, new PublicReadStampData(null, null), [], static function (PublicArtefactPermit $permit) use ($write): bool {
            $write($permit);

            return true;
        });
    }

    /** Seeds a static error or maintenance page fixture through the publication action. */
    public static function page(StaticErrorPageStore|StaticMaintenancePageStore $store, string $file, string $contents): void
    {
        throw_unless(PublishPublicArtefactAction::run($store, null, new PublicReadStampData(null, null), [], static function (PublicArtefactPermit $permit) use ($store, $file, $contents): bool {
            $store->put($permit, $file, $contents);

            return true;
        }), RuntimeException::class, 'The fixture could not be published.');
    }
}
