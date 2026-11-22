<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Activity;

use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\PageWorkflowState;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/**
 * Applies the page policy, including blueprint role restrictions, to activity
 * recorded against a page or a record owned by one. Subjects that no page
 * governs keep the site boundary alone.
 */
final class ActivityPageAccess
{
    /**
     * Hides activity whose governing page the actor may not view.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $activities
     * @return Builder<TModel>
     */
    public function scope(Builder $activities, ?Authenticatable $actor = null): Builder
    {
        $actor ??= auth()->user();

        if (! $actor instanceof User) {
            return $activities;
        }

        if ($actor->isGlobalAdmin()) {
            return $activities;
        }

        $hidden = SiteAccess::forActor($actor)->query(Page::class)->withTrashed()->inaccessibleToUser($actor);
        $hiddenIds = (clone $hidden)->select('id');
        $hiddenUuids = (clone $hidden)->select('uuid');
        $access = SiteAccess::forActor($actor);
        $pageMorph = (new Page)->getMorphClass();

        // Each owner type maps to the ids of its records that belong to a hidden page.
        // Types without a morph alias cannot be recorded as an activity subject.
        $owned = [
            Page::class => $hiddenIds,
            Translation::class => $access->query(Translation::class)
                ->where('translatable_type', $pageMorph)->whereIn('translatable_id', (clone $hidden)->select('id'))->select('id'),
            PagePropertyValue::class => $access->query(PagePropertyValue::class)
                ->whereIn('page_id', (clone $hidden)->select('id'))->select('id'),
            PageUrl::class => $access->query(PageUrl::class)
                ->where('pageable_type', $pageMorph)->whereIn('pageable_id', (clone $hidden)->select('id'))->select('id'),
            PageRevision::class => $access->query(PageRevision::class)->whereIn('page_uuid', $hiddenUuids)->select('id'),
            PageWorkflowState::class => $access->query(PageWorkflowState::class)->whereIn('page_uuid', (clone $hidden)->select('uuid'))->select('id'),
        ];
        $mapped = Relation::morphMap();

        return $activities->where(fn (Builder $visible): Builder => $visible
            ->whereNull('subject_type')
            ->orWhereNull('subject_id')
            ->orWhereNot(function (Builder $governed) use ($owned, $mapped): void {
                $governed->whereRaw('1 = 0');

                foreach ($owned as $class => $ids) {
                    $alias = array_search($class, $mapped, true);

                    if (! is_string($alias)) {
                        continue;
                    }

                    $governed->orWhere(fn (Builder $subject): Builder => $subject
                        ->where('subject_type', $alias)
                        ->whereIn('subject_id', $ids));
                }
            }));
    }

    /**
     * Reading the trail does not need page permissions, but a page the actor's
     * role may not open stays closed here too.
     */
    public function canView(Activity $activity, ?Authenticatable $actor = null): bool
    {
        $actor ??= auth()->user();
        $page = $this->governingPage($activity);

        if (! $page instanceof Page) {
            return true;
        }

        return $actor instanceof User && ($actor->isGlobalAdmin() || $page->isAccessibleByUser($actor));
    }

    /** Reverting rewrites the page, so the page policy's update decision applies. */
    public function canUpdate(Activity $activity, ?Authenticatable $actor = null): bool
    {
        $actor ??= auth()->user();
        $page = $this->governingPage($activity);

        if (! $page instanceof Page) {
            return true;
        }

        return $actor instanceof Authenticatable && Gate::forUser($actor)->allows('update', $page);
    }

    /**
     * The page whose policy governs the activity's subject, when there is one.
     */
    public function governingPage(Activity $activity): ?Page
    {
        $activity->loadMissing('subject');
        $subject = $activity->subject;

        $page = match (true) {
            $subject instanceof Page => $subject,
            $subject instanceof Translation => $subject->loadMissing('translatable')->translatable instanceof Page ? $subject->translatable : null,
            $subject instanceof PagePropertyValue => $subject->loadMissing('page')->page,
            $subject instanceof PageUrl => $subject->loadMissing('pageable')->pageable instanceof Page ? $subject->pageable : null,
            $subject instanceof PageRevision, $subject instanceof PageWorkflowState => $this->pageByUuid($subject->page_uuid),
            default => null,
        };

        if (! $page instanceof Page) {
            return null;
        }

        $page->loadMissing(['blueprint.roleRestrictions', 'site']);

        return $page;
    }

    private function pageByUuid(string $uuid): ?Page
    {
        return SiteAccess::current()->query(Page::class)
            ->withTrashed()
            ->with(['blueprint.roleRestrictions', 'site'])
            ->where('uuid', $uuid)
            ->first();
    }
}
