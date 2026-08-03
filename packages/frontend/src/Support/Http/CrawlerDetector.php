<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Http;

/**
 * Identifies automated clients from the User-Agent.
 *
 * The obvious `stripos($ua, 'bot')` heuristic is not good enough for anything
 * that changes what a client is served: it misses every crawler that does not
 * spell "bot" in its token — facebookexternalhit, WhatsApp, Slackbot-LinkExpanding
 * (matches, but only by luck), Google-InspectionTool, Chrome-Lighthouse, curl,
 * and the whole preview-fetcher family. Redirecting those on Accept-Language
 * would corrupt link previews and index the wrong language, so the detector is
 * an explicit, documented allowlist of substrings rather than a heuristic.
 *
 * `jaybizzle/crawler-detect` is NOT a dependency of this monorepo; adding one to
 * `packages/frontend` for a single header check is not warranted. If it is ever
 * pulled in, replace {@see self::matchesKnownCrawler()} and keep this façade.
 */
final class CrawlerDetector
{
    /**
     * Lower-case substrings matched against the User-Agent.
     *
     * Grouped by why each entry exists, because the cost of a wrong entry is
     * asymmetric: a false positive only disables detection for a human, a false
     * negative sends a crawler somewhere it should not go.
     *
     * @var list<string>
     */
    private const array SIGNATURES = [
        // Generic self-declared automation.
        'bot', 'crawler', 'spider', 'crawling', 'scraper', 'archiver', 'indexer',

        // Search engines that do not say "bot".
        'slurp', 'baiduspider', 'yandex', 'duckduckgo', 'sogou', 'exabot', 'ia_archiver',
        'google-inspectiontool', 'googleother', 'google favicon', 'mediapartners-google',
        'apis-google', 'feedfetcher-google', 'storebot-google', 'google-read-aloud',

        // Social and messaging link-preview fetchers.
        'facebookexternalhit', 'facebookcatalog', 'meta-externalagent', 'whatsapp',
        'twitterbot', 'linkedinbot', 'slackbot', 'slack-imgproxy', 'discordbot',
        'telegrambot', 'skypeuripreview', 'redditbot', 'pinterest', 'vkshare',
        'embedly', 'quora link preview', 'nuzzel', 'outbrain', 'flipboard',

        // AI and LLM fetchers.
        'gptbot', 'oai-searchbot', 'chatgpt-user', 'ccbot', 'claudebot', 'claude-web',
        'anthropic-ai', 'perplexitybot', 'perplexity-user', 'youbot', 'applebot',
        'bytespider', 'amazonbot', 'diffbot', 'cohere-ai', 'timpibot', 'omgili',

        // Auditing, monitoring and uptime tooling.
        'chrome-lighthouse', 'lighthouse', 'pagespeed', 'gtmetrix', 'pingdom',
        'uptimerobot', 'statuscake', 'site24x7', 'newrelicpinger', 'phantomjs',
        'headlesschrome', 'playwright', 'puppeteer', 'screaming frog', 'ahrefs',
        'semrush', 'mj12', 'dotbot', 'seokicks', 'dataprovider', 'petalbot',

        // Non-browser HTTP clients. These never carry a meaningful preference.
        'curl/', 'wget', 'python-requests', 'python-urllib', 'go-http-client',
        'java/', 'okhttp', 'axios/', 'node-fetch', 'guzzlehttp', 'libwww-perl',
        // `httpclient` already covers `Symfony HttpClient (Curl)`; a bare
        // `symfony` signature is deliberately absent, because that is the
        // User-Agent Symfony's own `Request::create()` synthesises for any
        // request built in-process, not a client we ever see on the wire.
        'httpclient', 'restsharp', 'postmanruntime', 'apachebench',
        'headless',
    ];

    public function isCrawler(?string $userAgent): bool
    {
        $userAgent = mb_trim((string) $userAgent);

        // An empty User-Agent is not a browser doing a real navigation either.
        if ($userAgent === '') {
            return true;
        }

        return $this->matchesKnownCrawler(mb_strtolower($userAgent));
    }

    private function matchesKnownCrawler(string $userAgent): bool
    {
        return array_any(
            self::SIGNATURES,
            static fn (string $signature): bool => str_contains($userAgent, $signature),
        );
    }
}
