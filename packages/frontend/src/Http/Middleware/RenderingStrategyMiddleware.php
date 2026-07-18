<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\Middleware;

use Capell\Core\Contracts\Pageable;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Enums\RenderingStrategyEnum;
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

            $strategy = RenderingStrategyEnum::tryFrom($page->meta['rendering_strategy'] ?? '')
                ?? RenderingStrategyEnum::BladeOnly;

            $response->headers->set('X-Rendering-Strategy', $strategy->value);
        } catch (Exception) {
            // Context may not be available; skip optimization
        }

        return $response;
    }
}
