<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;

final class PublicFrontendAssetUrl
{
    public function __construct(
        private readonly Application $application,
        private readonly UrlGenerator $url,
    ) {}

    public function to(string $path): string
    {
        $path = trim($path);

        if ($path === '' || str_contains($path, "\0")) {
            return '';
        }

        if ($this->isAllowedAbsoluteOrSpecialUrl($path)) {
            return $path;
        }

        if ($this->hasScheme($path) || str_starts_with($path, '//')) {
            return '';
        }

        $request = $this->application->bound('request')
            ? $this->application->make('request')
            : null;

        if ($request instanceof Request) {
            $origin = rtrim($request->getSchemeAndHttpHost() . $request->getBaseUrl(), '/');

            return $origin . '/' . ltrim($path, '/');
        }

        return $this->url->asset(ltrim($path, '/'));
    }

    private function isAllowedAbsoluteOrSpecialUrl(string $path): bool
    {
        return str_starts_with($path, '#')
            || str_starts_with(strtolower($path), 'http://')
            || str_starts_with(strtolower($path), 'https://')
            || str_starts_with(strtolower($path), 'data:image/');
    }

    private function hasScheme(string $path): bool
    {
        return preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1;
    }
}
