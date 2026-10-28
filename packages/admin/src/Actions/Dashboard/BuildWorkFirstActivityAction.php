<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Contracts\Dashboard\DashboardActivityContributor;
use Capell\Admin\Contracts\DashboardReports\ActivityTrailQueryProvider;
use Capell\Admin\Data\Dashboard\DashboardActivityItemData;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Permissions\SiteAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Activitylog\Models\Activity;

final class BuildWorkFirstActivityAction
{
    use AsFake;
    use AsObject;

    /** @return list<DashboardActivityItemData> */
    public function handle(Authenticatable $actor, ?int $siteId, ?string $language, int $limit = 5): array
    {
        if (! Gate::forUser($actor)->allows('viewAny', Activity::class)) {
            return [];
        }

        $query = SiteAccess::forActor($actor)->scope(resolve(ActivityTrailQueryProvider::class)->build())
            ->whereNotNull('causer_id')
            ->whereIn('event', ['created', 'updated', 'deleted'])
            ->whereHasMorph('subject', [Page::class, Translation::class], function (Builder $query, string $type) use ($siteId, $language): void {
                if ($type === Translation::class) {
                    $query->whereHasMorph('translatable', [Page::class], fn (Builder $pages): Builder => $pages
                        ->when($siteId !== null, fn (Builder $pages): Builder => $pages->where('site_id', $siteId)));
                    if ($language !== null) {
                        $query->whereHas('language', fn (Builder $languages): Builder => $languages->where('code', $language));
                    }
                } else {
                    $query->when($siteId !== null, fn (Builder $pages): Builder => $pages->where('site_id', $siteId))
                        ->when($language !== null, fn (Builder $pages): Builder => $pages->whereHas('translations.language', fn (Builder $languages): Builder => $languages->where('code', $language)));
                }
            })
            ->with(['causer', 'subject' => function (Relation $subject): void {
                if ($subject instanceof MorphTo) {
                    $subject->morphWith([Page::class => ['site'], Translation::class => ['translatable']]);
                }
            }])
            ->latest('created_at')->latest('id')->limit(100);
        $items = [];
        foreach ($query->get() as $activity) {
            if (! $activity instanceof Activity) {
                continue;
            }

            if (! Gate::forUser($actor)->allows('view', $activity)) {
                continue;
            }

            $properties = $activity->properties;
            $fields = array_keys((array) ($properties?->get('attributes', []) ?? []));
            if ($activity->event === 'updated' && array_diff($fields, ['updated_at', 'updated_by', 'created_at', 'created_by']) === []) {
                continue;
            }

            $subject = $activity->subject;
            $page = $subject instanceof Translation ? $subject->translatable : $subject;
            if (! $page instanceof Page) {
                continue;
            }

            if (! Gate::forUser($actor)->allows('view', $page)) {
                continue;
            }

            if ($activity->created_at === null) {
                continue;
            }

            $page->loadMissing('site');
            $key = $page->getKey() . ':' . $activity->causer_type . ':' . $activity->causer_id . ':' . $activity->event . ':' . intdiv($activity->created_at->getTimestamp(), 1800);
            if (isset($items[$key])) {
                $items[$key]->changes++;

                continue;
            }

            $items[$key] = new DashboardActivityItemData(
                key: 'activity-' . $activity->getKey(),
                siteId: (int) $page->site_id,
                siteName: $page->site->name,
                title: $page->name,
                actor: (string) ($activity->causer?->getAttribute('name') ?? __('capell-admin::work-first.former_teammate')),
                event: (string) $activity->event,
                occurredAt: CarbonImmutable::instance($activity->created_at)->toIso8601String(),
                url: Gate::forUser($actor)->allows('update', $page) ? GetEditPageResourceUrlAction::run($page) : null,
                pageId: (int) $page->getKey(),
            );
        }

        $items = array_values($items);
        foreach (app()->tagged(DashboardActivityContributor::TAG) as $contributor) {
            if ($contributor instanceof DashboardActivityContributor) {
                foreach ($contributor->build($actor, $siteId, $language, $limit) as $item) {
                    if ($item instanceof DashboardActivityItemData && resolve(CanViewDashboardItemAction::class)->handle($actor, $item->siteId, $siteId, $item->pageId, $language)) {
                        $items[] = $item;
                    }
                }
            }
        }

        usort($items, static fn (DashboardActivityItemData $left, DashboardActivityItemData $right): int => CarbonImmutable::parse($right->occurredAt)->getTimestamp() <=> CarbonImmutable::parse($left->occurredAt)->getTimestamp());

        return array_slice($items, 0, max(0, $limit));
    }
}
