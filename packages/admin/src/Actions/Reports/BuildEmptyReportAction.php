<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Reports;

use Capell\Admin\Contracts\Reports\BuildsReportSnapshot;
use Capell\Admin\Data\Reports\ReportSnapshotData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

abstract class BuildEmptyReportAction implements BuildsReportSnapshot
{
    use AsFake;
    use AsObject;

    abstract protected function reportKey(): string;

    abstract protected function emptyState(): string;

    public function handle(): ReportSnapshotData
    {
        return new ReportSnapshotData(
            key: $this->reportKey(),
            emptyState: $this->emptyState(),
        );
    }
}
