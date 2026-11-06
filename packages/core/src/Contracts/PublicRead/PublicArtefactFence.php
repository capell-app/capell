<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\PublicRead;

use Closure;

/**
 * A store of public artefacts served without Laravel (an HTML cache disk copy,
 * a static export), seen through its own exclusive lock.
 *
 * The lock has no lease: it is held until the callback returns, and every
 * withdrawal from the store holds it too. PublishPublicArtefactAction checks
 * the public-read rules inside it, so a render that started under the old
 * rules either writes before a withdrawal (and is removed by it) or is
 * refused.
 *
 * @experimental
 */
interface PublicArtefactFence
{
    /**
     * Runs $callback while holding the store's exclusive lock.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function exclusive(Closure $callback): mixed;

    /** Identifies the store, so a permit issued for it cannot write another. */
    public function fenceIdentity(): string;
}
