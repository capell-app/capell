<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Enums\PublishStatusEnum;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\TableName;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Describes the homepage of the site that was just installed: `editable` when it is
 * published and Admin can open it, `present_unverified` when it is published without an
 * edit URL, `draft` when it exists but is not live, `missing` when that site has no
 * homepage (or there is no site or pages table yet), and `unavailable` when the state
 * cannot be read or the install address does not match a site.
 *
 * @method static string run(?string $siteUrl = null)
 */
final class ResolveInstallFirstPageStatusAction
{
    use AsFake;
    use AsObject;

    public function handle(?string $siteUrl = null): string
    {
        try {
            if (! Schema::hasTable(TableName::resolve('pages')) || ! Schema::hasTable(TableName::resolve('sites'))) {
                return 'missing';
            }

            $siteId = $this->siteId($siteUrl);

            if ($siteId === false) {
                return 'missing';
            }

            if ($siteId === null) {
                return 'unavailable';
            }

            $page = Page::query()->with('blueprint')->where('site_id', $siteId)->homePage()->first();

            if (! $page instanceof Page) {
                return 'missing';
            }

            if ($page->publishStatus !== PublishStatusEnum::published) {
                return 'draft';
            }

            return GetEditPageResourceUrlAction::run($page) !== null
                ? 'editable'
                : 'present_unverified';
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    /**
     * @return int|false|null the installed site's id, false when no site exists, null when it cannot be identified
     */
    private function siteId(?string $siteUrl): int|false|null
    {
        if (! Site::query()->exists()) {
            return false;
        }

        $host = is_string($siteUrl) ? parse_url($siteUrl, PHP_URL_HOST) : null;

        if (is_string($host) && $host !== '') {
            $path = trim((string) parse_url((string) $siteUrl, PHP_URL_PATH), '/');
            $siteIds = SiteDomain::query()
                ->where('domain', strtolower($host))
                ->get(['site_id', 'path'])
                ->filter(fn (SiteDomain $domain): bool => trim((string) $domain->path, '/') === $path)
                ->pluck('site_id')
                ->unique();

            return $siteIds->count() === 1 ? (int) $siteIds->first() : null;
        }

        $siteIds = Site::query()->pluck('id');

        return $siteIds->count() === 1 ? (int) $siteIds->first() : null;
    }
}
