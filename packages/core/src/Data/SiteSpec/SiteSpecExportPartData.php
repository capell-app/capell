<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteSpec;

use Spatie\LaravelData\Data;

/** What one package contributes to a SiteSpec export, and what it had to leave out. */
final class SiteSpecExportPartData extends Data
{
    /**
     * @param  list<CapellSiteSpecNavigationData>  $navigations  Added to the spec's `navigations`.
     * @param  array<string, mixed>  $packageData  Stored under the exporter's key in the spec's `packageData`; left out when empty.
     * @param  list<string>  $omitted  Plain-language notes on what could not be written, shown to the person exporting.
     */
    public function __construct(
        public readonly array $navigations = [],
        public readonly array $packageData = [],
        public readonly array $omitted = [],
    ) {}
}
