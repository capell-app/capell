<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Cache;

use Capell\Admin\Contracts\Cache\StaticSiteGenerationDispatcher;
use Capell\Core\Models\Site;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static void run(Site $site) */
final class QueueStaticSiteGenerationAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly StaticSiteGenerationDispatcher $dispatcher) {}

    public function handle(Site $site): void
    {
        $this->dispatcher->dispatch($site);
    }
}
