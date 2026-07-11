<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Backup;

interface PageExporter
{
    /**
     * @param  array<int, int>  $pageIds
     * @param  array<string, mixed>  $options
     */
    public function exportPages(array $pageIds, array $options): string;

    /**
     * @param  array<int, int>  $siteIds
     * @param  array<string, mixed>  $options
     */
    public function exportSites(array $siteIds, array $options): string;
}
