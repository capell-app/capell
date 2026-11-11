<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Data\Dashboard\FirstRunStateData;
use Capell\Admin\Data\Dashboard\FirstRunTaskContextData;
use Capell\Admin\Data\Dashboard\FirstRunTaskData;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Support\Dashboard\FirstRunTaskRegistry;
use Capell\Admin\Support\Workspace\AdminWorkspacePreferenceStore;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\EventSourcing\Enums\PageVersionSource;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\FirstRun\SitePublicationMilestones;
use Capell\Core\Support\Permissions\SiteAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Works out which first-run tasks this install has completed, from the data it
 * already holds, so the checklist ticks itself and can never be out of date.
 *
 * First run is per site. The checklist is about the first site the actor can
 * access that has a home page and has never gone live; when the work-first
 * dashboard is scoped to one site, it is about that site only. A site goes
 * live the first time its home page is public (see SitePublicationMilestones)
 * and stays live even if the home page is unpublished later. With no such
 * site there is no first run.
 *
 * Packages add tasks through FirstRunTaskContributor; they sit before the
 * publish task, which always comes last.
 */
final class ResolveFirstRunStateAction
{
    use AsFake;
    use AsObject;

    /** @var list<string> */
    private const array BUILT_IN_KEYS = ['site', 'homepage', 'logo', 'address'];

    public function handle(Authenticatable $actor): FirstRunStateData
    {
        $home = $this->homeOfSiteBeingSetUp($actor);
        if (! $home instanceof Page) {
            return FirstRunStateData::notFirstRun();
        }

        $access = SiteAccess::forActor($actor);
        $siteId = (int) $home->site_id;
        $site = $access->query(Site::class)->whereKey($siteId)->first();

        $siteUrl = $site instanceof Site && SiteResource::canEdit($site)
            ? SiteResource::getUrl('edit', ['record' => $site])
            : null;
        $homeUrl = PageResource::canEdit($home)
            ? GetEditPageResourceUrlAction::run($home)
            : null;

        $builtIn = [
            $this->task('site', true, $siteUrl),
            $this->task('homepage', $this->editedByPerson($access, $home), $homeUrl),
            $this->task('logo', $access->query(Media::class)
                ->where('model_type', (new Site)->getMorphClass())
                ->where('model_id', $siteId)
                ->where('collection_name', MediaCollectionEnum::Logo->value)
                ->exists(), $siteUrl),
            $this->task('address', $this->hasPublicAddress($access, $siteId), $siteUrl),
        ];
        $contributed = resolve(FirstRunTaskRegistry::class)->contributedTasks(
            new FirstRunTaskContextData($actor, $siteId, (int) $home->getKey()),
            [...self::BUILT_IN_KEYS, 'publish'],
        );

        return new FirstRunStateData(
            true,
            [...$builtIn, ...$contributed, $this->task('publish', false, $homeUrl)],
            $actor instanceof Model && resolve(AdminWorkspacePreferenceStore::class)->firstRunChecklistHidden($actor),
        );
    }

    private function homeOfSiteBeingSetUp(Authenticatable $actor): ?Page
    {
        $milestones = resolve(SitePublicationMilestones::class);
        $published = $milestones->publishedSiteIds();
        // Only the work-first dashboard has a site picker, so only its saved scope names a current site.
        $scopedSiteId = $actor instanceof Model && config('capell-admin.dashboard.work_first.enabled', false) === true
            ? resolve(AdminWorkspacePreferenceStore::class)->dashboardScope($actor)?->siteId
            : null;

        $homes = SiteAccess::forActor($actor)->query(Page::class)->homePage()
            ->when($scopedSiteId !== null, fn (Builder $query): Builder => $query->where('site_id', $scopedSiteId))
            ->when($published !== [], fn (Builder $query): Builder => $query->whereNotIn('site_id', $published))
            ->orderBy('id')
            ->get();

        // A home page can go public without a publication transition (a scheduled
        // time passing, a form save, an import); record those sites as they are seen.
        $now = CarbonImmutable::now();
        $live = [];
        foreach ($homes as $home) {
            if (SitePublicationMilestones::isPublicHome($home, $now)) {
                $milestones->record((int) $home->site_id, $now);
                $live[(int) $home->site_id] = true;
            }
        }

        return $homes->first(fn (Page $home): bool => ! isset($live[(int) $home->site_id]));
    }

    private function task(string $key, bool $complete, ?string $url): FirstRunTaskData
    {
        [$title, $consequence, $actionLabel] = match ($key) {
            'site' => [
                (string) __('capell-admin::dashboard.first_run_task_site_title'),
                (string) __('capell-admin::dashboard.first_run_task_site_consequence'),
                (string) __('capell-admin::dashboard.first_run_task_site_action'),
            ],
            'homepage' => [
                (string) __('capell-admin::dashboard.first_run_task_homepage_title'),
                (string) __('capell-admin::dashboard.first_run_task_homepage_consequence'),
                (string) __('capell-admin::dashboard.first_run_task_homepage_action'),
            ],
            'logo' => [
                (string) __('capell-admin::dashboard.first_run_task_logo_title'),
                (string) __('capell-admin::dashboard.first_run_task_logo_consequence'),
                (string) __('capell-admin::dashboard.first_run_task_logo_action'),
            ],
            'address' => [
                (string) __('capell-admin::dashboard.first_run_task_address_title'),
                (string) __('capell-admin::dashboard.first_run_task_address_consequence'),
                (string) __('capell-admin::dashboard.first_run_task_address_action'),
            ],
            'publish' => [
                (string) __('capell-admin::dashboard.first_run_task_publish_title'),
                (string) __('capell-admin::dashboard.first_run_task_publish_consequence'),
                (string) __('capell-admin::dashboard.first_run_task_publish_action'),
            ],
            default => throw new InvalidArgumentException('Unknown first-run task: ' . $key),
        };

        return new FirstRunTaskData(
            key: $key,
            title: $title,
            consequence: $consequence,
            actionLabel: $actionLabel,
            complete: $complete,
            url: $url,
        );
    }

    /** A person has saved the page; imports, seeding and console runs have no actor. */
    private function editedByPerson(SiteAccess $access, Page $home): bool
    {
        return $access->query(PageRevision::class)
            ->where('page_uuid', $home->uuid)
            ->whereNotNull('actor_id')
            ->where('is_rollback', false)
            ->where(fn (Builder $query): Builder => $query->whereNull('source')->orWhere('source', PageVersionSource::Edit->value))
            ->exists();
    }

    private function hasPublicAddress(SiteAccess $access, int $siteId): bool
    {
        return $access->query(SiteDomain::class)
            ->where('site_id', $siteId)
            ->where('status', true)
            ->pluck('domain')
            ->contains(fn (mixed $domain): bool => is_string($domain) && ! $this->isLocalHost($domain));
    }

    private function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host));

        return $host === ''
            || in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local');
    }
}
