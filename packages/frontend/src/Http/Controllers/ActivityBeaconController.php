<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Controllers;

use Capell\Core\Actions\Activity\RecordActivityBucketAction;
use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Contracts\ActivitySettingsReader;
use Capell\Core\Enums\ActivityBucketSubjectEnum;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
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
        private readonly CrawlerDetector $crawlers,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $subjectType = $request->input('type');

        if (! is_string($subjectType) || ActivityBucketSubjectEnum::tryFrom($subjectType) !== ActivityBucketSubjectEnum::PageView) {
            return response()->json([], 204);
        }

        if (! $this->settings->collectionEnabled()
            || $this->crawlers->isCrawler($request->userAgent())
            || $this->honoursPrivacySignal($request)
            || $this->rateLimited($request)) {
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
        $resolved = LoadSiteDomainFromUrlAction::run($url, sites: SiteLoader::getSites());
        $siteDomain = is_array($resolved) ? $resolved[0] : null;
        if (! $siteDomain instanceof SiteDomain || ! $siteDomain->site instanceof Site) {
            throw new RuntimeException('Activity site could not be resolved.');
        }

        return $siteDomain;
    }

    private function honoursPrivacySignal(Request $request): bool
    {
        return $request->header('DNT') === '1' || $request->header('Sec-GPC') === '1';
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
