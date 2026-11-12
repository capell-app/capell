<?php

declare(strict_types=1);

namespace Capell\Core\Support\FirstRun;

use Capell\Core\Actions\FirstRun\ResolveGuidedSetupStateAction;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tells the pages the installer made for a site apart from pages a person added, so guided
 * setup can be offered to a fresh install that holds only the installer's home, not-found,
 * maintenance and welcome pages.
 *
 * Creating a site's default pages records the pages it added in the site's meta. A site
 * installed before that record existed has none, so its pages are also recognised by what
 * the installer always makes: a top-level not-found or maintenance page, and a top-level
 * standard page under the slug "welcome". Whether any page has been edited by a person is a
 * separate check, so an installer page someone has changed is still treated as their work.
 */
final class InstallerPages
{
    public const string META_KEY = 'installer_page_ids';

    private const string WELCOME_SLUG = 'welcome';

    /** Runs the page creation and records the pages it added to the site as the installer's own. */
    public static function recordCreatedDuring(Site $site, Closure $create): void
    {
        $before = self::pageIds($site);

        $create();

        $added = array_values(array_diff(self::pageIds($site), $before));

        if ($added === []) {
            return;
        }

        $fresh = Site::query()->whereKey($site->getKey())->firstOrFail();
        $meta = is_array($fresh->meta) ? $fresh->meta : [];
        $meta[self::META_KEY] = array_values(array_unique([...self::recordedIds($fresh), ...$added]));
        $fresh->forceFill(['meta' => $meta])->save();

        $site->setAttribute('meta', $meta);
        $site->syncOriginalAttribute('meta');
    }

    /** Whether the site holds a page, other than its home page, that the installer did not make. */
    public static function siteHasOtherPages(Site $site): bool
    {
        $recorded = self::recordedIds($site);

        return Page::query()
            ->where('site_id', $site->getKey())
            ->notHomePage()
            ->when($recorded !== [], fn (Builder $query): Builder => $query->whereNotIn('id', $recorded))
            ->whereNot(static function (Builder $query): void {
                self::installerShape($query);
            })
            ->exists();
    }

    /**
     * Whether this is the home page the installer published as a placeholder, before anyone set
     * the site up. Its being public is not a sign that anyone has published the site. Guided setup
     * drafts it before writing over it, so once the site is set up a public home page was
     * published by a person.
     */
    public static function isPlaceholderHome(Site $site, Page $home): bool
    {
        return self::isRecorded($site, $home) && ! ResolveGuidedSetupStateAction::isSetUp($site);
    }

    /** Whether creating the site's default pages recorded this page as one of its own. */
    public static function isRecorded(Site $site, Page $page): bool
    {
        return in_array((int) $page->getKey(), self::recordedIds($site), true);
    }

    /** @return list<int> */
    private static function recordedIds(Site $site): array
    {
        $ids = data_get($site->meta, self::META_KEY, []);

        return is_array($ids) ? array_values(array_map(intval(...), array_filter($ids, is_numeric(...)))) : [];
    }

    /** @return list<int> */
    private static function pageIds(Site $site): array
    {
        return array_values(Page::query()->where('site_id', $site->getKey())->pluck('id')->map(intval(...))->all());
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    private static function installerShape(Builder $query): Builder
    {
        return $query->whereNull('parent_id')->where(fn (Builder $shape): Builder => $shape
            ->whereHas('blueprint', fn (Builder $blueprint): Builder => $blueprint->whereIn('key', [PageTypeEnum::NotFound->value, PageTypeEnum::Maintenance->value]))
            ->orWhere(fn (Builder $welcome): Builder => $welcome
                ->whereHas('blueprint', fn (Builder $blueprint): Builder => $blueprint->where('key', PageTypeEnum::Default->value))
                ->whereHas('translations', fn (Builder $translation): Builder => $translation->where('meta->slug', self::WELCOME_SLUG))));
    }
}
