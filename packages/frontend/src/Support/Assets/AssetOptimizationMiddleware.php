<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\FrontendContextReader;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AssetOptimizationMiddleware
{
    public function __construct(private readonly FrontendContextReader $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldOptimize($response)) {
            return $response;
        }

        try {
            $this->injectAssetHints($response, $this->context);
        } catch (Exception) {
            // Context unavailable; skip optimization
        }

        return $response;
    }

    private function shouldOptimize(Response $response): bool
    {
        return $response->getStatusCode() === Response::HTTP_OK
            && str_contains($response->headers->get('Content-Type') ?? '', 'text/html');
    }

    private function injectAssetHints(Response $response, FrontendContextReader $context): void
    {
        $content = (string) $response->getContent();

        // Add resource hints for critical assets
        $theme = $context->theme();

        $hints = [];

        if ($theme instanceof Theme) {
            $themeUrl = $theme->getAttribute('assetUrl');
            if (is_string($themeUrl) && $themeUrl !== '') {
                $hints[] = '<link rel="dns-prefetch" href="' . $themeUrl . '">';
            }
        }

        // Add preload for critical CSS (theme stylesheet)
        if ($hints !== []) {
            $headEnd = strpos($content, '</head>');
            if ($headEnd !== false) {
                $content = substr_replace($content, implode('', $hints) . '</head>', $headEnd, 7);
                $response->setContent($content);
            }
        }
    }
}
