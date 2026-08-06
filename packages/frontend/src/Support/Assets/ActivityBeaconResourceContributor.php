<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Capell\Core\Contracts\ActivitySettingsReader;
use Capell\Core\Models\Page;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Contracts\FrontendResourceContributor;
use Capell\Frontend\Data\Assets\FrontendResourceContributionData;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\FrontendResourceContextData;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\Frontend\Support\Http\CrawlerDetector;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;

final class ActivityBeaconResourceContributor implements FrontendResourceContributor
{
    public function __construct(
        private readonly ActivitySettingsReader $settings,
        private readonly FrontendContextReader $frontendContext,
        private readonly CrawlerDetector $crawlers,
        private readonly Request $request,
        private readonly UrlGenerator $url,
    ) {}

    public function resources(FrontendResourceContextData $context): array
    {
        if (app()->runningInConsole()
            || ! $this->settings->collectionEnabled()
            || $this->frontendContext->isError()
            || ! $context->page instanceof Page
            || ! $context->page->shouldLogVisit()
            || $this->crawlers->isCrawler($this->request->userAgent())
            || $this->request->query('signature') !== null
            || $this->request->query('__theme_preview') !== null
        ) {
            return [];
        }

        $endpoint = json_encode($this->url->to('/_capell/activity'), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $script = <<<JS
(() => {
    if (navigator.doNotTrack === '1' || navigator.globalPrivacyControl === true) return;
    const endpoint = {$endpoint};
    const payload = JSON.stringify({ type: 'page_view', path: window.location.pathname });
    const send = () => {
        if (navigator.sendBeacon) {
            navigator.sendBeacon(endpoint, new Blob([payload], { type: 'application/json' }));
            return;
        }
        fetch(endpoint, { method: 'POST', body: payload, headers: { 'Content-Type': 'application/json' }, credentials: 'omit', keepalive: true }).catch(() => {});
    };
    send();
})();
JS;

        return [new FrontendResourceContributionData(FrontendResourceData::inlineScript(
            handle: 'capell-app/frontend:activity-beacon',
            package: FrontendServiceProvider::$packageName,
            content: $script,
        ))];
    }
}
