<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Controllers;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Support\Locale\HtmlLanguageAttribute;
use Capell\Core\ThemeStudio\Exceptions\ThemeNotFoundException;
use Capell\Frontend\Actions\PrepareFrontendRenderAction;
use Capell\Frontend\Actions\RenderFallbackPublicViewAction;
use Capell\Frontend\Actions\ResolveSystemPageAction;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\FrontendRenderContextData;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class PageController extends BaseController
{
    use AuthorizesRequests;
    use DispatchesJobs;
    use ValidatesRequests;

    public function __invoke(): SymfonyResponse|Responsable
    {
        $context = resolve(FrontendContextReader::class);
        $page = $context->page();

        if ($page !== null) {
            return $this->renderFrontendResponse(
                $context,
                new FrontendRenderContextData(
                    page: $page,
                    site: $context->site(),
                    language: $context->language(),
                    layout: $context->layout(),
                    theme: $context->theme(),
                    status: $context->isError() ? SymfonyResponse::HTTP_NOT_FOUND : null,
                    isError: $context->isError(),
                ),
            );
        }

        $site = $context->site();
        $language = $context->language();

        if ($site !== null && $language !== null) {
            $errorPage = ResolveSystemPageAction::run(PageTypeEnum::NotFound->value, $site, $language);

            if ($errorPage instanceof Pageable) {
                return $this->renderFrontendResponse(
                    $context,
                    new FrontendRenderContextData(
                        page: $errorPage,
                        site: $site,
                        language: $language,
                        layout: $context->layout(),
                        theme: $context->theme(),
                        status: SymfonyResponse::HTTP_NOT_FOUND,
                        isError: true,
                    ),
                );
            }
        }

        $this->recordMissingNotFoundPage($site === null ? 'site_unresolved' : ($language === null ? 'language_unresolved' : 'not_found_page_unresolved'));

        $fallbackResponse = RenderFallbackPublicViewAction::run(request());

        if ($fallbackResponse instanceof SymfonyResponse) {
            return $fallbackResponse;
        }

        return response()->noContent(404);
    }

    /**
     * Falling back to a plain view hides why the site's own not-found page was
     * not used, so the reason is recorded. A request without a site is routine
     * for unknown hosts; a resolved site without a not-found page is a defect.
     */
    private function recordMissingNotFoundPage(string $reason): void
    {
        try {
            $request = request();
            $host = mb_strtolower($request->getHost());

            // Every 404 on a broken site takes this path, bots included, so each
            // reason is reported at most once a minute per host.
            if (! Cache::add('capell:not-found-unavailable:' . $reason . ':' . $host, true, 60)) {
                return;
            }

            Log::log($reason === 'not_found_page_unresolved' ? 'warning' : 'debug', 'capell: public not-found page unavailable', [
                'reason' => $reason,
                'host' => $host,
                'path' => mb_substr($request->getPathInfo(), 0, 255),
            ]);
        } catch (Throwable) {
            // Diagnostics must never replace the response being rendered.
        }
    }

    private function renderFrontendResponse(
        FrontendContextReader $context,
        FrontendRenderContextData $renderContext,
    ): SymfonyResponse|Responsable {
        try {
            $preparedRender = PrepareFrontendRenderAction::run($context, $renderContext);
        } catch (ThemeNotFoundException) {
            return $this->diagnosticServiceUnavailableResponse(
                (string) __('capell-frontend::errors.theme_unavailable'),
                $renderContext->language,
            );
        }

        if ($preparedRender->renderer === null) {
            return $this->diagnosticServiceUnavailableResponse(
                (string) __('capell-frontend::errors.renderer_unavailable', [
                    'runtime' => $preparedRender->runtime === FrontendRuntime::Livewire ? 'Livewire' : 'Inertia',
                ]),
                $renderContext->language,
            );
        }

        return $preparedRender->renderer->render($preparedRender->renderContext);
    }

    private function diagnosticServiceUnavailableResponse(string $message, ?Language $language = null): SymfonyResponse
    {
        $title = (string) __('capell-frontend::errors.frontend_unavailable');
        $htmlLang = HtmlLanguageAttribute::forLanguage($language);

        return response(
            '<!doctype html><html lang="' . e($htmlLang) . '"><head><meta charset="utf-8"><title>'
                . e($title)
                . '</title></head><body><h1>'
                . e($title)
                . '</h1><p>'
                . e($message)
                . '</p></body></html>',
            SymfonyResponse::HTTP_SERVICE_UNAVAILABLE,
            [
                'Cache-Control' => 'private, no-store',
                'Content-Type' => 'text/html; charset=UTF-8',
            ],
        );
    }
}
