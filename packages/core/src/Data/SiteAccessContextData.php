<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

final class SiteAccessContextData extends Data
{
    public function __construct(
        public readonly Request $request,
        public readonly ?Site $site = null,
        public readonly ?SiteDomain $siteDomain = null,
        public readonly ?Authenticatable $user = null,
    ) {}
}
