<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Spatie\LaravelData\Data;

class FrontendRenderContextData extends Data
{
    public function __construct(
        public ?Pageable $page,
        public ?Site $site,
        public ?Language $language,
        public ?Layout $layout,
        public ?Theme $theme,
        public ?int $status = null,
        public bool $isError = false,
        public ?FrontendRuntimeManifestData $runtimeManifest = null,
        public ?PublicPageRenderData $publicRenderData = null,
    ) {}
}
