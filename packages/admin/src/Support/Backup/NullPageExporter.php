<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Backup;

use Capell\Admin\Contracts\Backup\PageExporter;
use RuntimeException;

class NullPageExporter implements PageExporter
{
    public function exportPages(array $pageIds, array $options): string
    {
        throw new RuntimeException('No page exporter is registered.');
    }

    public function exportSites(array $siteIds, array $options): string
    {
        throw new RuntimeException('No page exporter is registered.');
    }
}
