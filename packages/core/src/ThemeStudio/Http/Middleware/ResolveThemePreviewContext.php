<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Http\Middleware;

use Capell\Core\ThemeStudio\Preview\ThemePreviewContext;
use Capell\Core\ThemeStudio\Preview\ThemePreviewSigner;
use Closure;
use Illuminate\Http\Request;

class ResolveThemePreviewContext
{
    public function __construct(private readonly ThemePreviewSigner $signer) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $context = $request->routeIs('capell.admin.theme-preview')
            ? $this->signer->contextFromToken(
                is_string($request->query($this->signer->tokenParam()))
                    ? $request->query($this->signer->tokenParam())
                    : null,
            )
            : ThemePreviewContext::none();

        app()->instance(ThemePreviewContext::class, $context);
        view()->share('themePreviewContext', $context);

        try {
            return $next($request);
        } finally {
            app()->forgetInstance(ThemePreviewContext::class);
            view()->share('themePreviewContext', ThemePreviewContext::none());
        }
    }
}
