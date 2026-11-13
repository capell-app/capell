<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Controllers;

use Capell\Core\Actions\Activity\RecordActivityBucketAction;
use Capell\Core\Actions\Activity\RecordActivityVisitorAction;
use Capell\Core\Actions\SiteDomains\ResolveSiteDomainAction;
use Capell\Core\Contracts\ActivitySettingsReader;
use Capell\Core\Contracts\Privacy\ConsentDecisionProvider;
use Capell\Core\Data\SiteDomains\SiteRequestTargetData;
use Capell\Core\Enums\ActivityBucketSubjectEnum;
use Capell\Core\Enums\ConsentCategory;
use Capell\Core\Enums\ConsentDecision;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Privacy\ConsentSettings;
use Capell\Core\Support\Privacy\DefaultConsentDecisionProvider;
use Capell\Frontend\Support\Http\CrawlerDetector;
use Capell\Frontend\Support\Loader\SiteLoader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Throwable;

final class ActivityBeaconController
{
    public function __construct(
        private readonly ActivitySettingsReader $settings,
        private readonly RecordActivityBucketAction $record,
        private readonly RecordActivityVisitorAction $recordVisitor,
        private readonly CrawlerDetector $crawlers,
        private readonly ConsentDecisionProvider $consent,
        private readonly ConsentSettings $consentSettings,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $subjectType = $request->input('type');

        if (! is_string($subjectType) || ActivityBucketSubjectEnum::tryFrom($subjectType) !== ActivityBucketSubjectEnum::PageView) {
            return response()->json([], 204);
        }

        if (! $this->settings->collectionEnabled() || $this->crawlers->isCrawler($request->userAgent())) {
            return response()->json([], 204);
        }

        // Granted analytics records the page view and the visitor marker. Undecided records
        // only the aggregate count, and only under the first-party counter setting.
        $analytics = $this->analyticsDecision($request);
        $countsWithoutConsent = $analytics === ConsentDecision::Undecided && $this->consentSettings->firstPartyCounterEnabled();

        if (($analytics !== ConsentDecision::Granted && ! $countsWithoutConsent) || $this->rateLimited($request)) {
            return response()->json([], 204);
        }

        try {
            $subject = ActivityBucketSubjectEnum::from($subjectType);

            $path = $request->input('path');

            if (! is_string($path)
                || $path === ''
                || strlen($path) > 191
                || ! str_starts_with($path, '/')
                || str_contains($path, '?')
                || str_contains($path, '#')
            ) {
                return response()->json([], 204);
            }

            $siteDomain = $this->resolveSiteDomain($request, $path);
            $site = $siteDomain->site;
            $pageUrl = PageUrl::query()
                ->where('site_id', $site->getKey())
                ->where('language_id', $siteDomain->language_id ?? $site->language_id)
                ->where('url', rtrim($path, '/') ?: '/')
                ->whereNull('type')
                ->enabled()
                ->with(['language', 'pageable'])
                ->first();

            if (! $pageUrl instanceof PageUrl || ! $pageUrl->pageable instanceof Page) {
                return response()->json([], 204);
            }

            if (! $pageUrl->pageable->shouldLogVisit()) {
                return response()->json([], 204);
            }

            $language = $pageUrl->language?->code;

            if ($language === null || $language === '') {
                return response()->json([], 204);
            }

            $this->record->execute($site, $language, $subject, (string) $pageUrl->getKey());

            // A visitor marker is a pseudonymous identifier, so it needs granted analytics
            // on top of every gate above.
            if ($analytics === ConsentDecision::Granted) {
                $this->recordVisitor->execute($site, $language, $request->ip(), $request->userAgent());
            }

            return response()->json([], 204);
        } catch (Throwable $throwable) {
            Log::warning('Capell activity collection failed.', [
                'exception' => $throwable::class,
            ]);

            return response()->json([], 204);
        }
    }

    private function resolveSiteDomain(Request $request, string $path = '/'): SiteDomain
    {
        $url = $request->getSchemeAndHttpHost() . (str_starts_with($path, '/') ? $path : '/' . $path);
        $resolution = ResolveSiteDomainAction::run(
            SiteRequestTargetData::fromUrl($url),
            SiteLoader::getSites(),
        );
        $siteDomain = $resolution?->siteDomain;
        throw_if(! $siteDomain instanceof SiteDomain || ! $siteDomain->site instanceof Site, RuntimeException::class, 'Activity site could not be resolved.');

        return $siteDomain;
    }

    /**
     * A Global Privacy Control or Do Not Track signal refuses the counter whatever the provider
     * decides, as the browser beacon does. A provider that cannot decide is treated as a
     * refusal, never as consent.
     */
    private function analyticsDecision(Request $request): ConsentDecision
    {
        if (DefaultConsentDecisionProvider::hasOptOutSignal($request)) {
            return ConsentDecision::Denied;
        }

        try {
            return $this->consent->decide(ConsentCategory::Analytics->value, $request);
        } catch (Throwable $throwable) {
            report($throwable);

            return ConsentDecision::Denied;
        }
    }

    private function rateLimited(Request $request): bool
    {
        $ip = $request->ip() ?? 'unknown';
        $key = 'capell-activity:' . hash_hmac('sha256', $ip, (string) config('app.key'));
        $limit = max(1, (int) config('capell.analytics.rate_limit_per_minute', 30));

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }
}
