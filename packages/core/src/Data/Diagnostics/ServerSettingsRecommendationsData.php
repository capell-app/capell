<?php

declare(strict_types=1);

namespace Capell\Core\Data\Diagnostics;

use Spatie\LaravelData\Data;

final class ServerSettingsRecommendationsData extends Data
{
    /**
     * @param  list<ServerSettingRecommendationData>  $recommendations
     * @param  list<string>  $unmeasured  Setting names that could not be read.
     */
    public function __construct(
        public readonly array $recommendations,
        public readonly array $unmeasured,
    ) {}
}
