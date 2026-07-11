<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Activity;

use Filament\Resources\Resource as FilamentResource;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

final class ActivityResourceLinkData extends Data
{
    /**
     * @param  class-string<FilamentResource>|null  $resourceClass
     */
    public function __construct(
        public readonly Model $subject,
        public readonly Model $record,
        public readonly ?string $resourceClass,
        public readonly ?string $url,
        public readonly ?string $labelBasis,
        public readonly bool $usedProxyRecord,
        public readonly bool $usedIndexFallback,
    ) {}
}
