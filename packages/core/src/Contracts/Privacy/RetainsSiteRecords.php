<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Privacy;

use Capell\Core\Models\Site;

/**
 * Lets an optional package stop a site being permanently deleted while it holds records that must be kept,
 * such as submissions under legal hold that the database would otherwise cascade away with the site.
 *
 * Tag an implementation with {@see self::TAG}.
 */
interface RetainsSiteRecords
{
    public const string TAG = 'capell.site-record-retainers';

    /**
     * Records that must outlive the site, as a reader-facing description of each kind mapped to its count.
     * Return an empty array when nothing is held.
     *
     * @return array<string, int<1, max>>
     */
    public function retainedRecords(Site $site): array;
}
