<?php

declare(strict_types=1);

namespace Capell\Core\Data\Rendering;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

final class FrontendResourceContextData extends Data
{
    /** @param  Pageable<Model>|null  $page */
    public function __construct(
        public readonly ?Pageable $page,
        public readonly ?Site $site,
        public readonly ?Language $language,
        public readonly ?Layout $layout,
        public readonly ?Theme $theme,
        public readonly FrontendRuntimeManifestData $runtime,
    ) {}
}
