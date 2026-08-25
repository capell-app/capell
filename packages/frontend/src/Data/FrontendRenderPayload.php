<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Data\Assets\RenderedFrontendResourcesData;
use Capell\Frontend\Enums\FrontendRenderAudience;

/**
 * Typed view over the request-scoped fields {@see FrontendContextReader::setFrontendData()}
 * accumulates during a render. The bag itself (a plain string-keyed array,
 * accessible via getFrontendData()) still exists and its interface is
 * unchanged — this is a read-side addition, not a replacement of the
 * storage or a breaking interface change (CAP-0231 architecture review,
 * frontend#2).
 *
 * Every property here corresponds to a key some part of the frontend
 * pipeline currently sets by convention only, with no compile-time
 * guarantee the key is spelled correctly or holds the type readers expect.
 * Constructed on demand from the underlying bag by each
 * FrontendContextReader implementation's renderPayload(); it never becomes
 * the storage itself, so nothing about setFrontendData()'s write path
 * changes here.
 *
 * @see FrontendMediaHintData
 */
final readonly class FrontendRenderPayload
{
    public function __construct(
        public ?FrontendRuntimeManifestData $runtimeManifest = null,
        public ?PublicPageRenderData $publicPageRenderData = null,
        public ?FrontendResourcePlanData $resourcePlan = null,
        /** @var array<int, FrontendMediaHintData> */
        public array $mediaHints = [],
        public ?string $lcpMediaUrl = null,
        public ?PublicRenderPerformanceReportData $performanceReport = null,
        public bool $publicHtmlSafetyInspected = false,
        public ?string $publicHtmlSafetyInspectedHash = null,
        public ?RenderedFrontendResourcesData $renderedFrontendResources = null,
        /**
         * Read via getFrontendData('renderAudience') in
         * PublicViewQueryGuard/AssertPublicRenderContractAction, but nothing
         * in application code calls setFrontendData('renderAudience', ...) —
         * only tests do (AssertPublicRenderContractActionTest,
         * PublicViewQueryGuardTest). In production this is always null.
         * Carried through as-is rather than silently dropped or "fixed"
         * here; flagged as an open question in the architecture-review
         * checklist rather than folded into this typing change.
         */
        public ?FrontendRenderAudience $renderAudience = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data  The raw bag, as returned by getFrontendData(null).
     */
    public static function fromBag(array $data): self
    {
        $runtimeManifest = $data['runtimeManifest'] ?? null;
        $publicPageRenderData = $data['publicPageRenderData'] ?? null;
        $resourcePlan = $data['resourcePlan'] ?? null;
        $mediaHints = $data['mediaHints'] ?? [];
        $lcpMediaUrl = $data['lcpMediaUrl'] ?? null;
        $performanceReport = $data['performanceReport'] ?? null;
        $publicHtmlSafetyInspected = $data['publicHtmlSafetyInspected'] ?? false;
        $publicHtmlSafetyInspectedHash = $data['publicHtmlSafetyInspectedHash'] ?? null;
        $renderedFrontendResources = $data['renderedFrontendResources'] ?? null;
        $renderAudience = $data['renderAudience'] ?? null;

        if (! $renderAudience instanceof FrontendRenderAudience && is_string($renderAudience)) {
            // PublicViewQueryGuard::publicAudience() has tolerated a raw
            // string value here (not just the enum instance) since it was
            // written; preserved rather than narrowed away by this typing
            // change. Unknown strings resolve to Public, matching that call
            // site's own fallback.
            $renderAudience = FrontendRenderAudience::tryFrom($renderAudience) ?? FrontendRenderAudience::Public;
        }

        return new self(
            runtimeManifest: $runtimeManifest instanceof FrontendRuntimeManifestData ? $runtimeManifest : null,
            publicPageRenderData: $publicPageRenderData instanceof PublicPageRenderData ? $publicPageRenderData : null,
            resourcePlan: $resourcePlan instanceof FrontendResourcePlanData ? $resourcePlan : null,
            mediaHints: is_array($mediaHints) ? $mediaHints : [],
            lcpMediaUrl: is_string($lcpMediaUrl) ? $lcpMediaUrl : null,
            performanceReport: $performanceReport instanceof PublicRenderPerformanceReportData ? $performanceReport : null,
            publicHtmlSafetyInspected: $publicHtmlSafetyInspected === true,
            publicHtmlSafetyInspectedHash: is_string($publicHtmlSafetyInspectedHash) ? $publicHtmlSafetyInspectedHash : null,
            renderedFrontendResources: $renderedFrontendResources instanceof RenderedFrontendResourcesData ? $renderedFrontendResources : null,
            renderAudience: $renderAudience instanceof FrontendRenderAudience ? $renderAudience : null,
        );
    }
}
