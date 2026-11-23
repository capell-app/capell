<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Sites;

use Capell\Core\Contracts\Privacy\RetainsSiteRecords;
use Capell\Core\Models\Site;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array<string, int> run(Site $site)
 */
final class FindRetainedSiteRecordsAction
{
    use AsFake;
    use AsObject;

    /** @return array<string, int> */
    public function handle(Site $site): array
    {
        $retained = [];

        /** @var RetainsSiteRecords $retainer */
        foreach (app()->tagged(RetainsSiteRecords::TAG) as $retainer) {
            foreach ($retainer->retainedRecords($site) as $description => $count) {
                $retained[$description] = ($retained[$description] ?? 0) + $count;
            }
        }

        return $retained;
    }
}
