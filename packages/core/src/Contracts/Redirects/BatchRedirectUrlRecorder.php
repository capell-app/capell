<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Redirects;

use Capell\Core\Data\Redirects\RedirectUrlRecordData;

/**
 * A redirect recorder that can record many former addresses with a fixed
 * number of lookups, so a slug change on a large subtree does not query once
 * per descendant and language. Callers fall back to record() for recorders that
 * do not implement it.
 */
interface BatchRedirectUrlRecorder extends RedirectUrlRecorder
{
    /** @param  list<RedirectUrlRecordData>  $records */
    public function recordMany(array $records): void;
}
