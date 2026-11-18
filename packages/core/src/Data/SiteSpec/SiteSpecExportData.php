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

    /** A placeholder home page that has not been written yet. */
    public const string OMITTED_PLACEHOLDER = 'placeholder';

    /**
     * @param  array<string, list<string>>  $omittedPages  Names of pages left out, keyed by why (one of the `OMITTED_*` constants), sorted.
     */
    public function __construct(
        public readonly CapellSiteSpecData $spec,
        public readonly array $omittedPages = [],
    ) {}
}
