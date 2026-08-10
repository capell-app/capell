<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Illuminate\Foundation\Vite;

final class StylesheetRecoveryRenderer
{
    public function __construct(private readonly Vite $vite) {}

    public function enabled(): bool
    {
        return config('capell-frontend.stylesheet_recovery.enabled', true) === true
            && $this->fallbackUrl() !== null
            && $this->runtimeUrl() !== null;
    }

    public function linkAttributes(): string
    {
        $fallbackUrl = $this->fallbackUrl();

        if (! $this->enabled() || $fallbackUrl === null) {
            return '';
        }

        return ' data-capell-stylesheet-recovery data-capell-stylesheet-fallback="' . e($fallbackUrl) . '"';
    }

    public function runtimeTag(): string
    {
        $runtimeUrl = $this->runtimeUrl();

        if (! $this->enabled() || $runtimeUrl === null) {
            return '';
        }

        $nonce = $this->vite->cspNonce();
        $nonceAttribute = is_string($nonce) && $nonce !== '' ? ' nonce="' . e($nonce) . '"' : '';

        return '<script src="' . e($runtimeUrl) . '"' . $nonceAttribute . ' data-capell-stylesheet-recovery-runtime></script>';
    }

    private function fallbackUrl(): ?string
    {
        return $this->safeLocalUrl(config('capell-frontend.stylesheet_recovery.fallback_url'));
    }

    private function runtimeUrl(): ?string
    {
        return $this->safeLocalUrl(config('capell-frontend.stylesheet_recovery.runtime_url'));
    }

    private function safeLocalUrl(mixed $url): ?string
    {
        if (! is_string($url)
            || preg_match('#\A/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]+\z#D', $url) !== 1
            || str_starts_with($url, '//')
            || str_contains($url, '..')) {
            return null;
        }

        return $url;
    }
}
