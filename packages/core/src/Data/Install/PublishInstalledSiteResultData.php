<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Spatie\LaravelData\Data;

final class PublishInstalledSiteResultData extends Data
{
    /**
     * @param  int  $published  Draft pages that went live in this run.
     * @param  list<string>  $refused  Names of draft pages that could not be published.
     */
    public function __construct(
        public readonly int $published,
        public readonly array $refused = [],
    ) {}
}
