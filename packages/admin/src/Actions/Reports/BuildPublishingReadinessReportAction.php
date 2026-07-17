<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Reports;

use Capell\Admin\Actions\Publishing\BuildPublishReadinessAction;
use Capell\Admin\Contracts\Reports\BuildsReportSnapshot;
use Capell\Admin\Data\Reports\ReportFindingData;
use Capell\Admin\Data\Reports\ReportMetricData;
use Capell\Admin\Data\Reports\ReportSnapshotData;
use Capell\Admin\Enums\Reports\ReportFindingSeverity;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPublishingReadinessReportAction implements BuildsReportSnapshot
{
    use AsFake;
    use AsObject;

    private const int FINDING_LIMIT = 50;

    private const int PAGE_CHUNK_SIZE = 200;

    public function handle(): ReportSnapshotData
    {
        $findings = [];
        $blockedPageIds = [];
        $warningPageIds = [];
        $scheduledPageIds = [];
        $pageCount = 0;
        $totalFindingCount = 0;

        foreach ($this->pages() as $page) {
            $pageCount++;
            $pageFindings = $this->findingsForPage($page);

            if ($page->publishVisibilityState() === PublishVisibilityStateEnum::scheduled) {
                $scheduledPageIds[$page->getKey()] = true;
            }

            foreach ($pageFindings as $finding) {
                $totalFindingCount++;

                if ($finding->severity === ReportFindingSeverity::Critical) {
                    $blockedPageIds[$page->getKey()] = true;
                }

                if ($finding->severity === ReportFindingSeverity::Warning) {
                    $warningPageIds[$page->getKey()] = true;
                }

                if (count($findings) < self::FINDING_LIMIT) {
                    $findings[] = $finding;
                }
            }
        }

        $visibleWarningPageIds = array_diff_key($warningPageIds, $blockedPageIds);
        $readyPages = max(0, $pageCount - count($blockedPageIds) - count($visibleWarningPageIds));

        if ($totalFindingCount > self::FINDING_LIMIT) {
            $findings[] = new ReportFindingData(
                severity: ReportFindingSeverity::Info,
                title: __('capell-admin::reports.publishing_readiness_more_findings_title'),
                description: __('capell-admin::reports.publishing_readiness_more_findings_description', [
                    'count' => self::FINDING_LIMIT,
                ]),
            );
        }

        return new ReportSnapshotData(
            key: 'core.publishing_readiness',
            emptyState: __('capell-admin::reports.empty_state_publishing_readiness'),
            metrics: [
                new ReportMetricData(
                    label: __('capell-admin::reports.publishing_readiness_metric_pages_checked'),
                    value: $pageCount,
                    description: __('capell-admin::reports.publishing_readiness_metric_pages_checked_description'),
                ),
                new ReportMetricData(
                    label: __('capell-admin::reports.publishing_readiness_metric_ready_pages'),
                    value: $readyPages,
                    description: __('capell-admin::reports.publishing_readiness_metric_ready_pages_description'),
                ),
                new ReportMetricData(
                    label: __('capell-admin::reports.publishing_readiness_metric_blocked_pages'),
                    value: count($blockedPageIds),
                    description: __('capell-admin::reports.publishing_readiness_metric_blocked_pages_description'),
                ),
                new ReportMetricData(
                    label: __('capell-admin::reports.publishing_readiness_metric_scheduled_pages'),
                    value: count($scheduledPageIds),
                    description: __('capell-admin::reports.publishing_readiness_metric_scheduled_pages_description'),
                ),
            ],
            findings: $findings,
        );
    }

    /**
     * @return LazyCollection<int, Page>
     */
    private function pages(): LazyCollection
    {
        return Page::query()
            ->with([
                'layout:id,name',
                'pageUrls:id,pageable_type,pageable_id,site_id,language_id,status,url,type',
                'pageUrls.language:id,name,code',
                'site:id,name,language_id,admin',
                'site.language:id,name,code',
                'site.siteDomains:id,site_id,language_id,status',
                'site.siteDomains.language:id,name,code',
                'translations:id,translatable_type,translatable_id,language_id,title,content,meta',
                'translations.language:id,name,code',
                'blueprint:id,name,admin',
            ])
            ->latest('updated_at')
            ->select([
                'id',
                'name',
                'blueprint_id',
                'layout_id',
                'site_id',
                'visible_from',
                'visible_until',
                'deleted_at',
                'updated_at',
            ])
            ->lazy(self::PAGE_CHUNK_SIZE);
    }

    /**
     * @return list<ReportFindingData>
     */
    private function findingsForPage(Page $page): array
    {
        $findings = [];
        $blueprint = $page->getRelationValue('blueprint');
        $layout = $page->getRelationValue('layout');
        $editUrl = $blueprint instanceof Blueprint
            ? GetEditPageResourceUrlAction::run($page)
            : null;
        $recordLabel = $this->recordLabel($page);

        if (! $blueprint instanceof Blueprint) {
            $findings[] = new ReportFindingData(
                severity: ReportFindingSeverity::Critical,
                title: __('capell-admin::reports.publishing_readiness_missing_blueprint_title'),
                description: __('capell-admin::reports.publishing_readiness_missing_blueprint_description'),
                recordLabel: $recordLabel,
                actionLabel: __('capell-admin::reports.action_edit_page'),
                url: $editUrl,
            );
        }

        if (! $layout instanceof Layout) {
            $findings[] = new ReportFindingData(
                severity: ReportFindingSeverity::Critical,
                title: __('capell-admin::reports.publishing_readiness_missing_layout_title'),
                description: __('capell-admin::reports.publishing_readiness_missing_layout_description'),
                recordLabel: $recordLabel,
                actionLabel: __('capell-admin::reports.action_edit_page'),
                url: $editUrl,
            );
        }

        $readiness = BuildPublishReadinessAction::run($page);
        $visibilityState = $readiness->currentState;

        if ($visibilityState === PublishVisibilityStateEnum::expired) {
            $findings[] = new ReportFindingData(
                severity: ReportFindingSeverity::Critical,
                title: __('capell-admin::reports.publishing_readiness_expired_title'),
                description: __('capell-admin::reports.publishing_readiness_expired_description', [
                    'date' => $this->formatDate($page->visible_until),
                ]),
                recordLabel: $recordLabel,
                actionLabel: __('capell-admin::reports.action_edit_page'),
                url: $editUrl,
            );
        }

        if ($visibilityState === PublishVisibilityStateEnum::scheduled) {
            $findings[] = new ReportFindingData(
                severity: ReportFindingSeverity::Warning,
                title: __('capell-admin::reports.publishing_readiness_scheduled_title'),
                description: __('capell-admin::reports.publishing_readiness_scheduled_description', [
                    'date' => $this->formatDate($page->visible_from),
                ]),
                recordLabel: $recordLabel,
                actionLabel: __('capell-admin::reports.action_edit_page'),
                url: $editUrl,
            );
        }

        if ($visibilityState === PublishVisibilityStateEnum::draft) {
            $findings[] = new ReportFindingData(
                severity: ReportFindingSeverity::Warning,
                title: __('capell-admin::reports.publishing_readiness_draft_title'),
                description: __('capell-admin::reports.publishing_readiness_draft_description'),
                recordLabel: $recordLabel,
                actionLabel: __('capell-admin::reports.action_edit_page'),
                url: $editUrl,
            );
        }

        foreach ($this->languagesRequiringContent($page) as $language) {
            if (! $this->hasTranslation($page, $language)) {
                $findings[] = new ReportFindingData(
                    severity: ReportFindingSeverity::Critical,
                    title: __('capell-admin::reports.publishing_readiness_missing_translation_title', [
                        'language' => $language->name,
                    ]),
                    description: __('capell-admin::reports.publishing_readiness_missing_translation_description', [
                        'language' => $language->name,
                    ]),
                    recordLabel: $recordLabel,
                    actionLabel: __('capell-admin::reports.action_edit_page'),
                    url: $editUrl,
                );
            }
        }

        foreach ($this->languagesRequiringUrls($page) as $language) {
            if (! $this->hasAnyPageUrl($page, $language)) {
                $findings[] = new ReportFindingData(
                    severity: ReportFindingSeverity::Critical,
                    title: __('capell-admin::reports.publishing_readiness_missing_url_title', [
                        'language' => $language->name,
                    ]),
                    description: __('capell-admin::reports.publishing_readiness_missing_url_description', [
                        'language' => $language->name,
                    ]),
                    recordLabel: $recordLabel,
                    actionLabel: __('capell-admin::reports.action_edit_page'),
                    url: $editUrl,
                );

                continue;
            }

            if (! $this->hasActivePublicPageUrl($page, $language)) {
                $findings[] = new ReportFindingData(
                    severity: ReportFindingSeverity::Critical,
                    title: __('capell-admin::reports.publishing_readiness_disabled_url_title', [
                        'language' => $language->name,
                    ]),
                    description: __('capell-admin::reports.publishing_readiness_disabled_url_description', [
                        'language' => $language->name,
                    ]),
                    recordLabel: $recordLabel,
                    actionLabel: __('capell-admin::reports.action_edit_page'),
                    url: $editUrl,
                );
            }
        }

        return $findings;
    }

    /**
     * @return Collection<int, Language>
     */
    private function languagesRequiringContent(Page $page): Collection
    {
        $defaultLanguage = $this->defaultSiteLanguage($page);

        $languages = $defaultLanguage instanceof Language
            ? collect([$defaultLanguage])
            : collect();

        if (! $this->blueprintRequiresTranslations($page)) {
            return $languages->unique('id')->values();
        }

        return $languages
            ->merge($this->requiredSiteLanguages($page))
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, Language>
     */
    private function languagesRequiringUrls(Page $page): Collection
    {
        $requiredLanguages = $this->requiredSiteLanguages($page);

        if ($requiredLanguages->isNotEmpty()) {
            return $requiredLanguages;
        }

        $defaultLanguage = $this->defaultSiteLanguage($page);

        return $defaultLanguage instanceof Language ? collect([$defaultLanguage]) : collect();
    }

    /**
     * @return Collection<int, Language>
     */
    private function requiredSiteLanguages(Page $page): Collection
    {
        $site = $page->getRelationValue('site');

        if (! $site instanceof Site) {
            return collect();
        }

        $siteAdmin = $site->getAttribute('admin');
        $requiredLanguageCodes = is_array($siteAdmin) ? ($siteAdmin['require_translations'] ?? null) : null;

        if (! is_array($requiredLanguageCodes) || $requiredLanguageCodes === []) {
            return collect();
        }

        return $site
            ->getAllLanguages()
            ->filter(fn (Language $language): bool => in_array($language->code, $requiredLanguageCodes, true))
            ->values();
    }

    private function blueprintRequiresTranslations(Page $page): bool
    {
        $blueprint = $page->getRelationValue('blueprint');

        if (! $blueprint instanceof Blueprint) {
            return false;
        }

        $blueprintAdmin = $blueprint->getAttribute('admin');

        return is_array($blueprintAdmin) && (bool) ($blueprintAdmin['require_translations'] ?? false);
    }

    private function defaultSiteLanguage(Page $page): ?Language
    {
        $site = $page->getRelationValue('site');

        if (! $site instanceof Site) {
            return null;
        }

        $language = $site->getRelationValue('language');

        return $language instanceof Language ? $language : null;
    }

    private function hasTranslation(Page $page, Language $language): bool
    {
        return $page->translations->contains(
            fn (Translation $translation): bool => (int) $translation->language_id === (int) $language->id,
        );
    }

    private function hasAnyPageUrl(Page $page, Language $language): bool
    {
        return $page->pageUrls->contains(
            fn (PageUrl $pageUrl): bool => (int) $pageUrl->language_id === (int) $language->id,
        );
    }

    private function hasActivePublicPageUrl(Page $page, Language $language): bool
    {
        return $page->pageUrls->contains(fn (PageUrl $pageUrl): bool => (int) $pageUrl->language_id === (int) $language->id
                && $pageUrl->status
                && $pageUrl->type !== UrlTypeEnum::Redirect);
    }

    private function recordLabel(Page $page): string
    {
        $site = $page->getRelationValue('site');
        $siteName = $site instanceof Site ? $site->name : (string) __('capell-admin::reports.unknown_site');

        return sprintf('%s (%s)', $page->name, $siteName);
    }

    private function formatDate(mixed $date): string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format('j M Y, H:i');
        }

        return (string) $date;
    }
}
