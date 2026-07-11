<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

final class PublicPageResolutionInputData extends Data
{
    public function __construct(
        public readonly Site $site,
        public readonly Language $language,
        public readonly string $url,
        public readonly ?int $revisionPageId = null,
        public readonly bool $wildcardAlreadyAttempted = false,
        public readonly bool $abortMissingForBot = false,
        public readonly ?Request $request = null,
    ) {}
}
