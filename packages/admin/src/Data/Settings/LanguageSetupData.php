<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Settings;

use Spatie\LaravelData\Data;

final class LanguageSetupData extends Data
{
    public function __construct(
        public readonly string $applicationLocale,
        public readonly string $adminLocale,
        public readonly ?string $userUrl,
        public readonly ?string $sitesUrl,
    ) {}
}
