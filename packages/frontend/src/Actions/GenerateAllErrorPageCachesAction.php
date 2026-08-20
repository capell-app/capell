<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Models\Site;
use Capell\Frontend\Support\Error\ErrorPageRegenerationFingerprint;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run()
 */
final class GenerateAllErrorPageCachesAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly ErrorPageRegenerationFingerprint $fingerprint) {}

    public function handle(): int
    {
        $total = 0;

        Site::query()
            ->enabled()
            ->with(['language', 'siteDomains.language', 'theme', 'translations'])
            ->ordered()
            ->each(function (Site $site) use (&$total): void {
                $fingerprint = $this->fingerprint->current($site);
                GenerateErrorPageCacheAction::run($site);
                // Keep change-driven regeneration in step with what this run
                // published, so the next model change is compared against the
                // artefacts that actually exist.
                $this->fingerprint->remember((int) $site->getKey(), $fingerprint);
                $total++;
            });

        return $total;
    }
}
