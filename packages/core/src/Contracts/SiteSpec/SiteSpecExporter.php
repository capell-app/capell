<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\SiteSpec;

use Capell\Core\Data\SiteSpec\SiteSpecExportPartData;
use Capell\Core\Models\Site;

/**
 * Lets a package write its own part of a site into an exported SiteSpec, the
 * counterpart of {@see SiteSpecApplier}. The key must match the applier that reads
 * the part back, so an export applies cleanly wherever that package is installed.
 *
 * An exporter writes only what its applier restores without loss: anything the
 * spec cannot carry is reported in `omitted` and left out, never approximated.
 *
 * @api
 */
interface SiteSpecExporter
{
    public const string TAG = 'capell.site-spec.exporter';

    public function key(): string;

    /**
     * @param  array<int, string>  $pageSlugsById  Slugs of the pages the export carries, keyed by page ID. A page that is not listed is not in the spec.
     * @param  bool  $public  Whether the spec is public, so applying it updates live pages: leave out anything only members, or only some visitors, may see.
     */
    public function export(Site $site, array $pageSlugsById, bool $public): SiteSpecExportPartData;
}
