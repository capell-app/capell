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

        // Deliberately NOT deferred. The runtime works purely by registering
        // capture-phase 'error' and 'load' listeners on document; it performs no
        // retrospective scan for stylesheets that already failed. A deferred
        // script executes only after the document has been parsed, by which time
        // the recovery-eligible <link> has already been requested and may have
        // already fired its error event - so the listener is registered too late
        // to see it, and recovery silently never happens for exactly the case it
        // exists to handle: a stylesheet failing on initial load.
        //
        // The tag must therefore execute inline, before the stylesheet link is
        // parsed. That ordering is asserted by frontend-optimizer's
        // RenderProfileAssetRendererTest and documented in its
        // docs/critical-css.md ("load stylesheet-recovery.js before the
        // stylesheet").
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
