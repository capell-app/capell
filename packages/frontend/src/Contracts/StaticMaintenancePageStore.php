<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Core\Contracts\PublicRead\PublicArtefactFence;
use Capell\Core\Support\PublicRead\PublicArtefactPermit;

/**
 * Static maintenance pages are served without Laravel, so they are written only
 * through PublishPublicArtefactAction, under this store's lock, with the
 * permit it issues.
 */
interface StaticMaintenancePageStore extends PublicArtefactFence
{
    public function exists(string $file): bool;

    public function path(string $file): ?string;

    public function put(PublicArtefactPermit $permit, string $file, string $contents): void;
}
