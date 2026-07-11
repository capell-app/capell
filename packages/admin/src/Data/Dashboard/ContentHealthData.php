<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class ContentHealthData extends Data
{
    /**
     * @param  DataCollection<int, ContentHealthIssueData>  $issues
     */
    public function __construct(
        public readonly DataCollection $issues,
    ) {}
}
