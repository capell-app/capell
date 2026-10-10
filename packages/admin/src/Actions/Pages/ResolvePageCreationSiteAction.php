<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePageCreationSiteAction
{
    use AsFake;
    use AsObject;

    public function handle(int|string|null $siteId = null): ?Site
    {
        $siteId ??= request()->integer('site_id') ?: request()->integer('site')
            ?: session()->get('capell.current_site_id');

        return SiteAccess::current()->site(is_numeric($siteId) ? (int) $siteId : null);
    }
}
