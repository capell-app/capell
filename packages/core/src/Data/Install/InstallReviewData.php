<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Spatie\LaravelData\Data;

final class InstallReviewData extends Data
{
    /**
     * `items` is the flat label => text form shared with the browser installer (and fingerprinted there).
     * `lists` carries the same list-valued entries as real lists, so renderers never have to split text.
     *
     * @param  array<string, string>  $items
     * @param  array<string, list<string>>  $lists
     */
    public function __construct(
        public readonly array $items,
        public readonly array $lists = [],
    ) {}
}
