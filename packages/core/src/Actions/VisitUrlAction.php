<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Events\UrlVisitFailed;
use Capell\Core\Exceptions\OutboundRequestRefusedException;
use Capell\Core\Exceptions\UrlVisitFailedException;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Http\OutboundHttp;
use Capell\Core\Support\Http\OutboundHttpPolicy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(string $url, ?int $pageId = null)
 */
class VisitUrlAction implements ShouldBeUnique
{
    use AsFake;
    use AsJob;
    use AsObject;

    /** A visit only warms caches; it never needs more of the page than this. */
    private const int MAX_RESPONSE_BYTES = 10 * 1024 * 1024;

    protected string $url;

    public function getJobUniqueId(string $url, ?int $pageId = null): string
    {
        return 'visit_url_' . hash('sha256', $url . '|' . $pageId);
    }

    /** @throws UrlVisitFailedException A rejected or unsuccessful visit must fail generation and queue callers. */
    public function handle(string $url, ?int $pageId = null): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            Log::warning('VisitUrlAction: rejected non-http(s) url', ['url' => $url, 'scheme' => $scheme]);

            throw UrlVisitFailedException::forUrl($url, __('capell-core::message.visit_requires_http'));
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '' || ! $this->isAllowedHost($host)) {
            Log::warning('VisitUrlAction: rejected unsafe url', ['url' => $url, 'host' => $host]);

            throw UrlVisitFailedException::forUrl($url, __('capell-core::message.visit_requires_registered_host'));
        }

        try {
            $response = OutboundHttp::guarded(new OutboundHttpPolicy(
                maxBytes: self::MAX_RESPONSE_BYTES,
                connectTimeoutSeconds: 3,
                timeoutSeconds: 10,
            ))->get($url);
        } catch (OutboundRequestRefusedException $outboundRequestRefusedException) {
            Log::warning('VisitUrlAction: rejected unsafe url', ['url' => $url, 'host' => $host, 'reason' => $outboundRequestRefusedException->getMessage()]);

            throw UrlVisitFailedException::forUrl($url, __('capell-core::message.visit_requires_public_address'));
        }

        if (! $response->ok()) {
            Log::info('Problem accessing url', ['url' => $url, 'status' => $response->status()]);
            event(new UrlVisitFailed($url, $response->status(), $pageId));

            throw UrlVisitFailedException::forUrl($url, __('capell-core::message.visit_http_failed', ['status' => $response->status()]));
        }
    }

    private function isAllowedHost(string $host): bool
    {
        if (SiteDomain::query()
            ->where('domain', $host)
            ->enabled()
            ->exists()) {
            return true;
        }

        $appUrlHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $host === $appUrlHost
            && SiteDomain::query()
                ->whereNull('domain')
                ->enabled()
                ->exists();
    }
}
