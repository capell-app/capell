<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Support;

use Closure;

/**
 * Lets an in-memory test double stand in for an out-of-band artefact store:
 * PublishPublicArtefactAction takes its "lock" (it simply runs the callback)
 * and binds permits to this instance.
 */
trait FencesTestArtefactStore
{
    public function exclusive(Closure $callback): mixed
    {
        return $callback();
    }

    public function fenceIdentity(): string
    {
        return 'test-store:' . spl_object_hash($this);
    }
}
