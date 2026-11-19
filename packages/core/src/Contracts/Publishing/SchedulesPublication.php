<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Publishing;

use Capell\Core\Support\Publishing\ScheduledPublicationRegistry;
use Carbon\CarbonInterface;

/**
 * Implemented by an Eloquent model other than Page whose public visibility follows
 * nullable `visible_from` and `visible_until` columns, such as a blog article or an
 * event. Registering the model class with
 * {@see ScheduledPublicationRegistry} lets Core's
 * scheduled-publication sweep clear its public caches when either moment passes,
 * so the package needs no scheduler of its own.
 *
 * @property CarbonInterface|null $visible_from
 * @property CarbonInterface|null $visible_until
 *
 * @experimental
 */
interface SchedulesPublication
{
    /**
     * Surrogate keys of every cached public response or fragment that shows this
     * record, such as `page-{id}` and `site-{site_id}`. The sweep purges them when the
     * record goes live or expires.
     *
     * @return list<string>
     */
    public function scheduledPublicationSurrogateKeys(): array;
}
