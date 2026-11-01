<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Redirects;

use Capell\Core\Data\Redirects\RedirectUrlRecordData;
use Illuminate\Database\Eloquent\Model;

/**
 * A redirect recorder that can store many redirects with a bounded number of reads.
 *
 * Callers that record redirects for a whole subtree should prefer this over calling record() per URL.
 */
interface BatchRedirectUrlRecorder extends RedirectUrlRecorder
{
    /** @param  list<RedirectUrlRecordData<covariant Model>>  $records */
    public function recordMany(array $records): void;
}
