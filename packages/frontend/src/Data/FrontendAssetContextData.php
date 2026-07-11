<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Spatie\LaravelData\Data;

class FrontendAssetContextData extends Data
{
    /**
     * @param  array<int, mixed>  $widgetResourceUsages
     */
    public function __construct(
        public ?Pageable $page,
        public ?Site $site,
        public ?Language $language,
        public ?Layout $layout,
        public ?Theme $theme,
        public FrontendRuntimeManifestData $runtime,
        public array $widgetResourceUsages = [],
    ) {}
}
