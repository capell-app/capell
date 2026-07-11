<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Middleware;

use Capell\Frontend\Actions\RenderFallbackPublicViewAction;
use Capell\Frontend\Contracts\FrontendKernelInterface;
use Capell\Frontend\Support\State\FrontendState;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ResolveFrontendMiddleware
{
    public function __construct(
        private readonly FrontendKernelInterface $kernel,
        private readonly FrontendState $state,
    ) {}

    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        $this->state->reset();

        try {
            $result = $this->kernel->bootstrap($request);
        } catch (NotFoundHttpException $notFoundHttpException) {
            $fallbackResponse = $this->fallbackViewResponse($request);

            if ($fallbackResponse instanceof Response) {
                return $fallbackResponse;
            }

            throw $notFoundHttpException;
        }

        if ($result->isRedirect() && $result->redirect instanceof RedirectResponse) {
            return $result->redirect;
        }

        if ($result->isError()) {
            $status = $result->error?->status;
            $message = $result->error?->message;

            if ($status === 404) {
                $fallbackResponse = $this->fallbackViewResponse($request);

                if ($fallbackResponse instanceof Response) {
                    return $fallbackResponse;
                }
            }

            throw_if($status === 404, NotFoundHttpException::class, $message);

            throw new HttpException($status, $message);
        }

        return $next($request);
    }

    private function fallbackViewResponse(Request $request): ?Response
    {
        return RenderFallbackPublicViewAction::run($request);
    }
}
