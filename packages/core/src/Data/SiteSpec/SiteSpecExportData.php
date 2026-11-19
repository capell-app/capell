<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteSpec;

use Spatie\LaravelData\Data;

/** A site written out as a SiteSpec, with the pages that could not be included. */
final class SiteSpecExportData extends Data
{
    /** No lowercase, hyphenated slug in the site language. */
    public const string OMITTED_NO_SLUG = 'no_slug';

    /** The page has no page type to export. */
    public const string OMITTED_NO_PAGE_TYPE = 'no_page_type';

    /** The body is built from blocks, which a SiteSpec page cannot carry. */
    public const string OMITTED_NOT_HTML = 'not_html';

    /** A public export leaves out pages that are not live, or whose visibility is restricted, because applying it would create them live and open. */
    public const string OMITTED_NOT_PUBLIC = 'not_public';

    /** A placeholder home page that has not been written yet. */
    public const string OMITTED_PLACEHOLDER = 'placeholder';

    /** Page properties that point at an ID, or hold several values, which a SiteSpec cannot carry. */
    public const string OMITTED_PROPERTIES = 'properties';

    /** Images an import could not download from the address the spec would give. */
    public const string OMITTED_MEDIA = 'media';

    /**
     * @param  array<string, list<string>>  $omittedPages  Names of pages left out, keyed by why (one of the `OMITTED_*` constants), sorted.
     * @param  array<string, list<string>>  $omittedParts  Notes on what a part of the site could not carry, keyed by the part: `properties`, `media` or a package exporter's key.
     */
    public function __construct(
        public readonly CapellSiteSpecData $spec,
        public readonly array $omittedPages = [],
        public readonly array $omittedParts = [],
    ) {}
}
