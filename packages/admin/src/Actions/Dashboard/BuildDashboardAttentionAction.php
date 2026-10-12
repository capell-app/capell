<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Admin\Actions\Publishing\BuildPublishReadinessAction;
use Capell\Admin\Data\Reports\ReportFindingData;
use Capell\Admin\Enums\Reports\ReportFindingSeverity;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildDashboardAttentionAction
{
    use AsFake;
    use AsObject;

    /** @return list<ReportFindingData> */
    public function handle(Authenticatable $actor, ?int $siteId = null): array
    {
        if (! PageResource::canViewAny()) {
            return [];
        }

        return array_values(SiteAccess::forActor($actor)->query(Page::class)
            ->when($siteId !== null, fn (Builder $query): Builder => $query->where('site_id', $siteId))
            ->where(fn (Builder $query): Builder => $query->whereDoesntHave('blueprint')
                ->orWhereDoesntHave('layout')->orWhereDoesntHave('translations')
                ->orWhereDoesntHave('pageUrls', fn (Builder $urls): Builder => $urls->enabled()))
            ->orderByRaw('CASE WHEN blueprint_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->limit(3)
            ->get(['id', 'name', 'site_id', 'blueprint_id', 'layout_id', 'visible_from', 'visible_until', 'deleted_at'])
            ->map(fn (Page $page): ReportFindingData => $this->finding($page))
            ->all());
    }

    private function finding(Page $page): ReportFindingData
    {
        $missingBlueprint = $page->getAttribute('blueprint_id') === null;
        $readiness = BuildPublishReadinessAction::run($page);

        return new ReportFindingData(
            severity: ReportFindingSeverity::Critical,
            title: (string) __('capell-admin::dashboard.attention_page_setup'),
            description: (string) __('capell-admin::dashboard.attention_blocked_description', ['count' => count($readiness->blockingCheckIds)]),
            recordLabel: $page->name,
            actionLabel: (string) __('capell-admin::dashboard.review_content'),
            url: ! $missingBlueprint && PageResource::canEdit($page)
                    ? GetEditPageResourceUrlAction::run($page)
                    : PageResource::getUrl(),
            id: 'page-setup-' . $page->getKey(),
        );
    }
}
