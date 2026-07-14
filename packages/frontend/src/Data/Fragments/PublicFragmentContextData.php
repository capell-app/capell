<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Fragments;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

final class PublicFragmentContextData extends Data
{
    public function __construct(
        public readonly Model&Pageable $page,
        public readonly Site $site,
        public readonly Language $language,
        public readonly PublicFragmentReferenceData $reference,
    ) {}
}
