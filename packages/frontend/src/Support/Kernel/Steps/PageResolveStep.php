<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Kernel\Steps;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\ResolvePublicPageRequestAction;
use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Data\PageResolutionData;
use Capell\Frontend\Data\PublicPageResolutionInputData;
use Capell\Frontend\Support\Logging\FrontendLogger;
use Capell\Frontend\Support\Routing\PageResolutionRouteMetadataApplier;
use Closure;
use Illuminate\Http\RedirectResponse;

final class PageResolveStep
{
    public function __construct(
        private readonly FrontendLogger $logger,
        private readonly PageResolutionRouteMetadataApplier $routeMetadataApplier,
    ) {}

    public function handle(FrontendWork $work, Closure $next): mixed
    {
        $site = $work->state->site();
        $language = $work->state->language();

        if (! $site instanceof Site || ! $language instanceof Language) {
            return $next($work);
        }

        $url = $work->state->effectiveUrl() ?? ($work->state->relativePath() ?? '/');
        $revisionPageId = $work->state->revisionPageId();

        // Only log resolving page if revisionPageId is present (diagnostic)
        if ($revisionPageId !== null) {
            $this->logger->debug('[Frontend] Resolving revision page', [
                'url' => $url,
                'site' => $site->id,
                'language' => $language->code,
                'revisionPageId' => $revisionPageId,
            ]);
        }

        $resolution = ResolvePublicPageRequestAction::run(new PublicPageResolutionInputData(
            site: $site,
            language: $language,
            url: $url,
            revisionPageId: $revisionPageId,
            wildcardAlreadyAttempted: $work->request->attributes->get('_frontend_wildcard_attempted') === true,
            abortMissingForBot: $this->shouldAbortForBot($work),
            request: $work->request,
        ));

        if ($resolution->redirect instanceof RedirectResponse) {
            $work->setRedirect($resolution->redirect);

            return $work;
        }

        if ($resolution->attemptedWildcard) {
            $work->request->attributes->set('_frontend_wildcard_attempted', true);
        }

        if ($resolution->shouldAbort404 || ! $resolution->page instanceof Pageable) {
            $this->set404($work);

            return $work;
        }

        $this->applyResolution($work, $resolution);

        return $next($work);
    }

    private function shouldAbortForBot(FrontendWork $work): bool
    {
        $ua = $work->request->headers->get('User-Agent') ?? '';

        return $ua !== '' && stripos($ua, 'bot') !== false;
    }

    private function applyResolution(FrontendWork $work, PageResolutionData $resolution): void
    {
        if (! $resolution->page instanceof Pageable) {
            return;
        }

        $work->state->withPage($resolution->page);
        $work->state->markAsError($resolution->isErrorPage);

        if ($resolution->params !== []) {
            $work->state->withParams($resolution->params);
        }

        if ($resolution->slug !== null) {
            $work->state->withSlug($resolution->slug);
        }

        $this->routeMetadataApplier->apply($work->request, $resolution);
    }

    private function set404(FrontendWork $work): void
    {
        $work->setError(['status' => 404, 'message' => 'Page not found']);
    }
}
