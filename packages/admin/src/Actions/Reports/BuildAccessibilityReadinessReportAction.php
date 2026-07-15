<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Reports;

use Capell\Admin\Contracts\Reports\BuildsReportSnapshot;
use Capell\Admin\Data\Reports\AccessibilityReadinessFindingData;
use Capell\Admin\Data\Reports\ReportFindingData;
use Capell\Admin\Data\Reports\ReportMetricData;
use Capell\Admin\Data\Reports\ReportSnapshotData;
use Capell\Admin\Enums\Reports\ReportFindingSeverity;
use Capell\Admin\Filament\Resources\Media\MediaResource;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class BuildAccessibilityReadinessReportAction implements BuildsReportSnapshot
{
    use AsObject;

    public function handle(?Site $site = null): ReportSnapshotData
    {
        $siteId = $site?->getKey();
        $sites = Site::query()
            ->when($siteId !== null, fn ($query) => $query->whereKey($siteId))
            ->with('language')
            ->get();
        $siteIds = $sites->modelKeys();
        $languagesBySite = $sites->mapWithKeys(
            fn (Site $currentSite): array => [$currentSite->getKey() => $this->requiredLanguages($currentSite)],
        );
        $pages = Page::query()
            ->whereIn('site_id', $siteIds)
            ->with(['blueprint', 'site', 'translations.language', 'pageUrls'])
            ->get();
        $findings = [];

        foreach ($pages as $page) {
            $languages = $languagesBySite->get($page->site_id, collect());

            foreach ($languages as $language) {
                if (! $this->hasTranslation($page, $language)) {
                    $findings[] = $this->pageFinding(
                        id: 'accessibility.translation.required-missing',
                        page: $page,
                        language: $language,
                        title: __('capell-admin::reports.accessibility_missing_translation_title', ['language' => $language->name]),
                        description: __('capell-admin::reports.accessibility_missing_translation_description'),
                        remediation: __('capell-admin::reports.accessibility_missing_translation_remediation'),
                    );
                }

                if (! $this->hasHealthyUrl($page, $language)) {
                    $findings[] = $this->pageFinding(
                        id: 'accessibility.url.localized-broken',
                        page: $page,
                        language: $language,
                        title: __('capell-admin::reports.accessibility_broken_url_title', ['language' => $language->name]),
                        description: __('capell-admin::reports.accessibility_broken_url_description'),
                        remediation: __('capell-admin::reports.accessibility_broken_url_remediation'),
                    );
                }
            }
        }

        $images = $this->imagesForPages($pages);

        foreach ($images as $media) {
            $ownerPage = $this->ownerPage($media);

            if (! $ownerPage instanceof Page) {
                continue;
            }

            $languages = $languagesBySite->get($ownerPage->site_id, collect());

            foreach ($languages as $language) {
                array_push($findings, ...$this->mediaFindings($media, $language));
            }
        }

        $reportFindings = array_map(
            static fn (AccessibilityReadinessFindingData $finding): ReportFindingData => $finding->toReportFinding(),
            $findings,
        );

        return new ReportSnapshotData(
            key: 'core.accessibility_readiness',
            emptyState: __('capell-admin::reports.empty_state_accessibility_readiness'),
            metrics: [
                new ReportMetricData(__('capell-admin::reports.accessibility_metric_pages'), $pages->count()),
                new ReportMetricData(__('capell-admin::reports.accessibility_metric_images'), $images->count()),
                new ReportMetricData(__('capell-admin::reports.accessibility_metric_findings'), count($reportFindings)),
            ],
            findings: $reportFindings,
        );
    }

    /** @return Collection<int, Language> */
    private function requiredLanguages(Site $site): Collection
    {
        $admin = $site->admin;
        $requiredCodes = is_array($admin) ? ($admin['require_translations'] ?? []) : [];

        if (! is_array($requiredCodes) || $requiredCodes === []) {
            return $site->language instanceof Language
                ? new Collection([$site->language])
                : new Collection;
        }

        return Language::query()->whereIn('code', $requiredCodes)->where('status', true)->get();
    }

    private function hasTranslation(Page $page, Language $language): bool
    {
        return $page->translations->contains(
            fn (Translation $translation): bool => (int) $translation->language_id === (int) $language->getKey(),
        );
    }

    private function hasHealthyUrl(Page $page, Language $language): bool
    {
        return $page->pageUrls->contains(
            fn (PageUrl $url): bool => (int) $url->language_id === (int) $language->getKey()
                && $url->status
                && $url->url !== ''
                && $url->type !== UrlTypeEnum::Redirect,
        );
    }

    private function pageFinding(
        string $id,
        Page $page,
        Language $language,
        string $title,
        string $description,
        string $remediation,
    ): AccessibilityReadinessFindingData {
        return new AccessibilityReadinessFindingData(
            id: $id,
            severity: ReportFindingSeverity::Critical,
            title: $title,
            description: $description,
            recordLabel: $page->name,
            remediation: $remediation,
            evidence: [
                'page_id' => $page->getKey(),
                'site_id' => $page->site_id,
                'language' => $language->code,
            ],
            url: $page->blueprint !== null ? GetEditPageResourceUrlAction::run($page) : null,
        );
    }

    /**
     * @param  Collection<int, Page>  $pages
     * @return Collection<int, Media>
     */
    private function imagesForPages(Collection $pages): Collection
    {
        $translationIds = $pages->flatMap(
            fn (Page $page) => $page->translations->modelKeys(),
        )->all();

        return Media::query()
            ->where('mime_type', 'like', 'image/%')
            ->where('model_type', (new Translation)->getMorphClass())
            ->whereIn('model_id', $translationIds)
            ->with(['model', 'translations.language'])
            ->get();
    }

    private function ownerPage(Media $media): ?Page
    {
        $owner = $media->model;

        if (! $owner instanceof Translation) {
            return null;
        }

        $owner->loadMissing('translatable');

        return $owner->translatable instanceof Page ? $owner->translatable : null;
    }

    /** @return list<AccessibilityReadinessFindingData> */
    private function mediaFindings(Media $media, Language $language): array
    {
        $translation = $media->translations->first(
            fn (Translation $candidate): bool => (int) $candidate->language_id === (int) $language->getKey(),
        );
        $meta = $translation instanceof Translation && is_array($translation->meta) ? $translation->meta : [];
        $recordLabel = $media->name . ' · ' . $language->name;
        $evidence = [
            'media_id' => $media->getKey(),
            'language' => $language->code,
        ];
        $url = $this->mediaEditUrl($media);

        if (! array_key_exists('decorative', $meta)) {
            return [new AccessibilityReadinessFindingData(
                id: 'accessibility.media.decorative-intent-missing',
                severity: ReportFindingSeverity::Warning,
                title: __('capell-admin::reports.accessibility_decorative_intent_title'),
                description: __('capell-admin::reports.accessibility_decorative_intent_description'),
                recordLabel: $recordLabel,
                remediation: __('capell-admin::reports.accessibility_decorative_intent_remediation'),
                evidence: $evidence,
                url: $url,
            )];
        }

        if ($meta['decorative'] === true) {
            return [];
        }

        $findings = [];

        foreach (['alt', 'caption', 'credit'] as $field) {
            if (is_string($meta[$field] ?? null) && trim($meta[$field]) !== '') {
                continue;
            }

            $findings[] = new AccessibilityReadinessFindingData(
                id: 'accessibility.media.localized-' . $field . '-missing',
                severity: $field === 'alt' ? ReportFindingSeverity::Critical : ReportFindingSeverity::Warning,
                title: __('capell-admin::reports.accessibility_media_field_title', [
                    'field' => __('capell-admin::reports.accessibility_media_field_' . $field),
                    'language' => $language->name,
                ]),
                description: __('capell-admin::reports.accessibility_media_field_description'),
                recordLabel: $recordLabel,
                remediation: __('capell-admin::reports.accessibility_media_field_remediation'),
                evidence: [...$evidence, 'field' => $field],
                url: $url,
            );
        }

        return $findings;
    }

    private function mediaEditUrl(Media $media): ?string
    {
        try {
            return MediaResource::getUrl('edit', ['record' => $media]);
        } catch (Throwable) {
            return null;
        }
    }
}
