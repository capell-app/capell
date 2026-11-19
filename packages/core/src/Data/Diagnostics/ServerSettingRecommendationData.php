<?php

declare(strict_types=1);

namespace Capell\Core\Data\Diagnostics;

use Capell\Core\Enums\Diagnostics\ServerSettingFlag;
use Spatie\LaravelData\Data;

final class ServerSettingRecommendationData extends Data
{
    public function __construct(
        public readonly ServerSettingFlag $flag,
        public readonly string $setting,
        public readonly string $measured,
        public readonly string $recommended,
    ) {}
}
