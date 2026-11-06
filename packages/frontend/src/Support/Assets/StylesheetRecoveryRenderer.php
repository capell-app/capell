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
        // the recovery-eligible <link> may have already fired its error event, so
        // recovery would silently never happen for a stylesheet failing on
        // initial load. The tag must execute before the stylesheet link is
        // parsed; that ordering is asserted by frontend-optimizer's
        // RenderProfileAssetRendererTest and documented in its
        // docs/critical-css.md.
        //
        // When a nonce authorises it, the published runtime is inlined so ordering costs no blocking
        // request in every public head. The external tag remains the fallback
        // when the published file cannot be read or could close the element.
        $inline = $nonceAttribute !== '' ? $this->inlineRuntime($runtimeUrl) : null;

        if ($inline !== null) {
            return '<script' . $nonceAttribute . ' data-capell-stylesheet-recovery-runtime>' . $inline . '</script>';
        }

        return '<script src="' . e($runtimeUrl) . '"' . $nonceAttribute . ' data-capell-stylesheet-recovery-runtime></script>';
    }

    private function inlineRuntime(string $runtimeUrl): ?string
    {
        $path = public_path(ltrim((string) parse_url($runtimeUrl, PHP_URL_PATH), '/'));
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) && $contents !== '' && stripos($contents, '</script') === false && ! str_contains($contents, '<!--')
            ? rtrim($contents)
            : null;
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
