<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Capell\Admin\Contracts\Dashboard\ContentHealthDataProvider;
use Capell\Admin\Data\Dashboard\ContentHealthData;
use Capell\Admin\Data\Dashboard\ContentHealthIssueData;
use Spatie\LaravelData\DataCollection;

final class NullContentHealthDataProvider implements ContentHealthDataProvider
{
    public function build(): ContentHealthData
    {
        return new ContentHealthData(
            issues: ContentHealthIssueData::collect([], DataCollection::class),
        );
    }
}
