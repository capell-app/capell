<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Controllers;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\ThemeStudio\Exceptions\ThemeNotFoundException;
use Capell\Frontend\Actions\AssertPublicRenderContractAction;
use Capell\Frontend\Actions\PrepareFrontendRenderAction;
use Capell\Frontend\Actions\RenderFallbackPublicViewAction;
use Capell\Frontend\Actions\ResolveSystemPageAction;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Support\Render\PageVariantNegotiatorRegistry;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
            $renderContext = new FrontendRenderContextData(
                page: $page,
                site: $context->site(),
                language: $context->language(),
                layout: $context->layout(),
                theme: $context->theme(),
                status: $context->isError() ? SymfonyResponse::HTTP_NOT_FOUND : null,
                isError: $context->isError(),
            );

            $pageVariants = resolve(PageVariantNegotiatorRegistry::class);
            $variantResponse = $pageVariants->negotiate(request(), $renderContext);

            if ($variantResponse instanceof SymfonyResponse) {
                if ($renderContext->status !== null) {
                    $variantResponse->setStatusCode($renderContext->status);
                }

                AssertPublicRenderContractAction::run($variantResponse);

                return $variantResponse;
            }

            if ($pageVariants->wasVariantRequested(request())) {
                return response()->noContent(SymfonyResponse::HTTP_NOT_FOUND);
            }

            return $this->renderFrontendResponse($context, $renderContext);
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

        $fallbackResponse = RenderFallbackPublicViewAction::run(request());

        if ($fallbackResponse instanceof SymfonyResponse) {
            return $fallbackResponse;
        }

        return response()->noContent(404);
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
            );
        }

        if ($preparedRender->renderer === null) {
            return $this->diagnosticServiceUnavailableResponse(
                (string) __('capell-frontend::errors.renderer_unavailable', [
                    'runtime' => $preparedRender->runtime === FrontendRuntime::Livewire ? 'Livewire' : 'Inertia',
                ]),
            );
        }

        return $preparedRender->renderer->render($preparedRender->renderContext);
    }

    private function diagnosticServiceUnavailableResponse(string $message): SymfonyResponse
    {
        $title = (string) __('capell-frontend::errors.frontend_unavailable');

        return response(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>'
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
