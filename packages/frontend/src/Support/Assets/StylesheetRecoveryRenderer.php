<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Illuminate\Foundation\Vite;

final class StylesheetRecoveryRenderer
{
    private string|false|null $runtimeSource = false;

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

        // Deliberately NOT deferred. The runtime works purely by registering
        // capture-phase 'error' and 'load' listeners on document; it performs no
        // retrospective scan for stylesheets that already failed. A deferred
        // script executes only after the document has been parsed, by which time
        // the recovery-eligible <link> has already been requested and may have
        // already fired its error event - so the listener is registered too late
        // to see it, and recovery silently never happens for exactly the case it
        // exists to handle: a stylesheet failing on initial load.
        //
        // The runtime must therefore execute before the stylesheet link is
        // parsed. It is inlined so the page makes no parser-blocking request for
        // it. An inline script is authorised by a content hash (see
        // cspHashSource()), never a per-response nonce, so cached and exported
        // HTML stays valid under any later response's policy. Without the
        // runtime file, or with inlining disabled, the external script remains.
        $source = $this->inlineEnabled() ? $this->runtimeSource() : null;

        if ($source !== null) {
            return '<script data-capell-stylesheet-recovery-runtime>' . $source . '</script>';
        }

        $nonce = $this->vite->cspNonce();
        $nonceAttribute = is_string($nonce) && $nonce !== '' ? ' nonce="' . e($nonce) . '"' : '';

        return '<script src="' . e($runtimeUrl) . '"' . $nonceAttribute . ' data-capell-stylesheet-recovery-runtime></script>';
    }

    /**
     * The CSP script-src source that authorises the inlined runtime, for example
     * 'sha256-...'. Null when the runtime is not inlined.
     */
    public function cspHashSource(): ?string
    {
        $source = $this->enabled() && $this->inlineEnabled() ? $this->runtimeSource() : null;

        return $source === null ? null : "'sha256-" . base64_encode(hash('sha256', $source, true)) . "'";
    }

    private function inlineEnabled(): bool
    {
        return config('capell-frontend.stylesheet_recovery.inline', true) === true;
    }

    private function runtimeSource(): ?string
    {
        if ($this->runtimeSource === false) {
            $path = dirname(__DIR__, 3) . '/publishes/build/stylesheet-recovery.js';
            $contents = is_file($path) ? file_get_contents($path) : false;
            $this->runtimeSource = is_string($contents)
                && trim($contents) !== ''
                && ! str_contains(strtolower($contents), '</script')
                && ! str_contains($contents, '<!--')
                ? trim($contents)
                : null;
        }

        return $this->runtimeSource;
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
