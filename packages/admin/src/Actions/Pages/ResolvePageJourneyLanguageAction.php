<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Support\AuthoringLinkRequestCache;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePageJourneyLanguageAction
{
    use AsFake;
    use AsObject;

    public function handle(?int $siteId, mixed $languageId): ?Language
    {
        if ((! is_int($languageId) && ! is_string($languageId)) || ! ctype_digit((string) $languageId)) {
            return null;
        }

        $site = AuthoringLinkRequestCache::remember(
            self::class . ':' . $siteId,
            fn (SiteAccess $access): ?Site => $access->query(Site::class)->with(['siteDomains.language', 'language'])->find($siteId),
        );

        if (! $site instanceof Site) {
            return null;
        }

        return $this->forSite($site, $languageId);
    }

    /** Resolve membership after the caller has authorised access to this site. */
    public function forSite(Site $site, mixed $languageId): ?Language
    {
        if ((! is_int($languageId) && ! is_string($languageId)) || ! ctype_digit((string) $languageId)) {
            return null;
        }

        $site->loadMissing(['siteDomains.language', 'language']);

        return $site->getAllLanguages()->firstWhere('id', (int) $languageId);
    }
}
