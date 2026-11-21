<?php

declare(strict_types=1);

use Capell\Core\Actions\Security\ResolveCspHashSourcesAction;
use Capell\Frontend\Support\Assets\StylesheetRecoveryRenderer;

it('offers the inlined stylesheet recovery runtime hash through the shared CSP registry', function (): void {
    config([
        'capell-frontend.stylesheet_recovery.enabled' => true,
        'capell-frontend.stylesheet_recovery.inline' => true,
        'capell-frontend.stylesheet_recovery.fallback_url' => '/vendor/capell-frontend/capell-frontend.css',
        'capell-frontend.stylesheet_recovery.runtime_url' => '/vendor/capell-frontend/stylesheet-recovery.js',
    ]);

    $hash = resolve(StylesheetRecoveryRenderer::class)->cspHashSource();
    $html = resolve(StylesheetRecoveryRenderer::class)->runtimeTag();

    expect($hash)->not->toBeNull()
        ->and(ResolveCspHashSourcesAction::run()->script)->toContain($hash)
        ->and($html)->toStartWith('<script data-capell-stylesheet-recovery-runtime>')
        ->and($html)->not->toContain('nonce=');
});
