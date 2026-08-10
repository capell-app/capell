<?php

declare(strict_types=1);

namespace Capell\Core\Data\Publishing;

use Capell\Core\Models\Contracts\Publishable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

final class PublicationLocaleStatusContextData extends Data
{
    public function __construct(
        public readonly Model&Publishable $record,
        public readonly Site $site,
        public readonly Language $language,
        public readonly CarbonImmutable $now,
    ) {}
}
