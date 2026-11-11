<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Data\Dashboard\FirstRunStateData;
use Capell\Admin\Data\Dashboard\FirstRunTaskData;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\EventSourcing\Enums\PageVersionSource;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Works out which first-run tasks this install has completed, from the data it
 * already holds, so the checklist ticks itself and can never be out of date.
 *
 * The install is in its first-run state until the home page of a site the actor
 * can access is public. Anything else (no site or home page yet, or a live
 * site) is not first-run, so established installs never see the checklist.
 */
final class ResolveFirstRunStateAction
{
    use AsFake;
    use AsObject;

    public function handle(Authenticatable $actor): FirstRunStateData
    {
        $access = SiteAccess::forActor($actor);
        $homes = $access->query(Page::class)->homePage()->orderBy('id')->get();

        // First-run only while no accessible site has a public home page, so a
        // live install with an unlaunched extra site never shows the checklist.
        if ($homes->isEmpty() || $homes->contains(fn (Page $page): bool => $this->isPublic($page))) {
            return FirstRunStateData::notFirstRun();
        }

        $home = $homes->firstOrFail();
        $siteId = (int) $home->site_id;
        $site = $access->query(Site::class)->whereKey($siteId)->first();

        $siteUrl = $site instanceof Site && SiteResource::canEdit($site)
            ? SiteResource::getUrl('edit', ['record' => $site])
            : null;
        $homeUrl = PageResource::canEdit($home)
            ? GetEditPageResourceUrlAction::run($home)
            : null;

        return new FirstRunStateData(true, [
            $this->task('site', true, $siteUrl),
            $this->task('homepage', $this->editedByPerson($access, $home), $homeUrl),
            $this->task('logo', $access->query(Media::class)
                ->where('model_type', (new Site)->getMorphClass())
                ->where('model_id', $siteId)
                ->where('collection_name', MediaCollectionEnum::Logo->value)
                ->exists(), $siteUrl),
            $this->task('address', $this->hasPublicAddress($access, $siteId), $siteUrl),
            $this->task('publish', false, $homeUrl),
        ]);
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

    private function isPublic(Page $home): bool
    {
        if ($home->publishVisibilityState() !== PublishVisibilityStateEnum::published) {
            return false;
        }

        $robots = data_get($home->meta, 'robots');

        return ! filter_var(data_get($home->meta, 'noindex'), FILTER_VALIDATE_BOOL)
            && (! is_string($robots) || ! str_contains(strtolower($robots), 'noindex'));
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
