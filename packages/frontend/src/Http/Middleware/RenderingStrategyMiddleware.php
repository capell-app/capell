<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Middleware;

use Capell\Core\Contracts\Pageable;
use Capell\Frontend\Actions\ResolveRenderingStrategyAction;
use Capell\Frontend\Contracts\FrontendContextReader;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RenderingStrategyMiddleware
{
    public function __construct(private readonly FrontendContextReader $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $page = $this->context->page();

            if (! $page instanceof Pageable) {
                return $response;
            }

            $strategy = ResolveRenderingStrategyAction::run($page);

            $response->headers->set('X-Rendering-Strategy', $strategy->value);
        } catch (Exception) {
            // Context may not be available; skip optimization
        }

        return $response;
    }
}
