<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Models\Site;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run()
 */
final class GenerateAllMaintenancePageCachesAction
{
    use AsFake;
    use AsObject;

    public function handle(): int
    {
        $total = 0;

        Site::query()
            ->enabled()
            ->with(['language', 'siteDomains.language', 'theme', 'translations'])
            ->ordered()
            ->each(function (Site $site) use (&$total): void {
                GenerateMaintenancePageCacheAction::run($site);
                $total++;
            });

        return $total;
    }
}
