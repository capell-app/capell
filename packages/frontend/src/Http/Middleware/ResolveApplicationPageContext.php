<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Middleware;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Exceptions\ThemeNotFoundException;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\RenderFrontendUnavailableResponseAction;
use Capell\Frontend\Actions\ResolveLayoutFileAction;
use Capell\Frontend\Contracts\ApplicationNavigationResolver;
use Capell\Frontend\Data\ApplicationAreaData;
use Capell\Frontend\Data\ApplicationPageData;
use Capell\Frontend\Data\FrontendBootstrapResult;
use Capell\Frontend\Support\Application\ApplicationAreaRegistry;
use Capell\Frontend\Support\Cache\PrivateFrontendResponse;
use Capell\Frontend\Support\Kernel\FrontendKernel;
use Capell\Frontend\Support\Kernel\FrontendKernelSteps;
use Capell\Frontend\Support\Kernel\Steps\ApplicationPageResolveStep;
use Capell\Frontend\Support\Kernel\Steps\ApplyLocaleStep;
use Capell\Frontend\Support\Kernel\Steps\PageResolveStep;
use Capell\Frontend\Support\Kernel\Steps\SetUrlGeneratorStep;
use Capell\Frontend\Support\Kernel\Steps\SiteResolveStep;
use Capell\Frontend\Support\Loader\PageCachePolicy;
use Capell\Frontend\Support\Locale\FrontendLocaleScope;
use Capell\Frontend\Support\Logging\FrontendLogger;
use Capell\Frontend\Support\State\FrontendState;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `frontend.application:{area}` renders a host route inside the active theme: it
 * resolves the site, the area's shell page (by system page type), its layout and
 * theme, and marks the response private so no HTML cache stores it.
 *
 * @experimental
 */
final class ResolveApplicationPageContext
{
    public function __construct(
        private readonly ApplicationAreaRegistry $areas,
        private readonly ApplicationNavigationResolver $navigation,
        private readonly FrontendState $state,
        private readonly FrontendLocaleScope $localeScope,
        private readonly FrontendLogger $logger,
        private readonly Pipeline $pipeline,
    ) {}

    /**
     * The configured kernel steps with the shell page step in place of the URL page
     * step. Callable guard steps a host configured are kept, so they run here too.
     * Without a URL page step, the shell page step goes after the site and locale
     * steps it depends on.
     *
     * @return list<callable|string>
     */
    public static function steps(): array
    {
        $steps = array_values(FrontendKernelSteps::configured());
        $pageStep = array_search(PageResolveStep::class, $steps, true);

        if ($pageStep !== false) {
            $steps[$pageStep] = ApplicationPageResolveStep::class;

            return $steps;
        }

        $insertAt = 0;

        foreach ([SiteResolveStep::class, ApplyLocaleStep::class, SetUrlGeneratorStep::class] as $dependency) {
            $position = array_search($dependency, $steps, true);

            if ($position !== false) {
                $insertAt = max($insertAt, $position + 1);
            }
        }

        array_splice($steps, $insertAt, 0, [ApplicationPageResolveStep::class]);

        return $steps;
    }

    public function handle(Request $request, Closure $next, string $area): Response
    {
        $areaData = $this->areas->get($area);

        throw_unless(
            $areaData instanceof ApplicationAreaData,
            InvalidArgumentException::class,
            sprintf('No application area [%s] is registered with %s.', $area, ApplicationAreaRegistry::class),
        );

        // Mark before anything renders, so even an early refusal is never cached.
        PrivateFrontendResponse::mark($request);
        $request->attributes->set(ApplicationPageResolveStep::AREA_ATTRIBUTE, $areaData);

        $this->localeScope->restore();
        $this->state->reset();

        try {
            return $this->privateResponse($this->resolve($request, $next, $areaData));
        } finally {
            $this->localeScope->restore();
        }
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    private function resolve(Request $request, Closure $next, ApplicationAreaData $area): Response
    {
        try {
            $result = new FrontendKernel($this->pipeline, self::steps(), $this->state)->bootstrap($request);
        } catch (ThemeNotFoundException) {
            return RenderFrontendUnavailableResponseAction::run((string) __('capell-frontend::errors.theme_unavailable'));
        }

        if ($result->isRedirect() && $result->redirect instanceof RedirectResponse) {
            return $result->redirect;
        }

        if ($result->isError()) {
            return $this->errorResponse($result, $area);
        }

        $page = $this->state->page();
        $site = $this->state->site();
        $language = $this->state->language();

        if (! $page instanceof Pageable || ! $site instanceof Site || ! $language instanceof Language) {
            return $this->unavailable(__('capell-frontend::errors.application_page_missing', ['type' => $area->pageType]));
        }

        if (PageCachePolicy::shouldCache($page) || ! PageCachePolicy::neverCached($page)) {
            $this->logger->warning('[Frontend] Refused a cacheable application shell page', [
                'area' => $area->key,
                'type' => $area->pageType,
            ]);

            return $this->unavailable(__('capell-frontend::errors.application_page_cacheable', ['type' => $area->pageType]), $language);
        }

        $applicationPage = new ApplicationPageData(
            area: $area,
            page: $page,
            site: $site,
            language: $language,
            layout: $this->state->layout(),
            theme: $this->state->theme(),
            layoutFile: ResolveLayoutFileAction::run($this->state->layout()),
            navigation: $this->navigation->resolve($area, $site, $language, $request),
        );

        $request->attributes->set(ApplicationPageData::ATTRIBUTE, $applicationPage);

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', $applicationPage->robots);

        return $response;
    }

    private function errorResponse(FrontendBootstrapResult $result, ApplicationAreaData $area): Response
    {
        $status = $result->error?->status ?? Response::HTTP_INTERNAL_SERVER_ERROR;

        if ($status === ApplicationPageResolveStep::MISSING_PAGE_STATUS) {
            return $this->unavailable(__('capell-frontend::errors.application_page_missing', ['type' => $area->pageType]), $this->state->language());
        }

        throw_if($status === Response::HTTP_NOT_FOUND, NotFoundHttpException::class, $result->error?->message);

        throw new HttpException($status, $result->error?->message);
    }

    private function unavailable(mixed $message, ?Language $language = null): Response
    {
        return RenderFrontendUnavailableResponseAction::run(is_string($message) ? $message : '', $language);
    }

    private function privateResponse(Response $response): Response
    {
        return PrivateFrontendResponse::applyHeaders($response);
    }
}
