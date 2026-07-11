<?php

declare(strict_types=1);

use Capell\Core\ThemeStudio\Http\Middleware\ResolveThemePreviewContext;
use Capell\Core\ThemeStudio\Preview\ThemePreviewContext;
use Capell\Core\ThemeStudio\Preview\ThemePreviewSigner;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

it('ignores theme preview tokens outside the admin theme preview route', function (): void {
    $signer = new ThemePreviewSigner('preview-test-secret');
    $request = Request::create('/', Symfony\Component\HttpFoundation\Request::METHOD_GET, [
        $signer->tokenParam() => $signer->generate('preview-theme', 'editorial'),
    ]);
    $request->setRouteResolver(fn (): Route => new Route('GET', '/', fn (): string => 'ok')->name('home'));

    $context = ThemePreviewContext::none();

    new ResolveThemePreviewContext($signer)->handle($request, function () use (&$context): string {
        $context = resolve(ThemePreviewContext::class);

        return 'ok';
    });

    expect($context->previewing)->toBeFalse()
        ->and($context->themeKey)->toBeNull()
        ->and($context->presetKey)->toBeNull();
});

it('accepts theme preview tokens on the admin theme preview route', function (): void {
    $signer = new ThemePreviewSigner('preview-test-secret');
    $request = Request::create('/admin/theme-preview/1/1/1', Symfony\Component\HttpFoundation\Request::METHOD_GET, [
        $signer->tokenParam() => $signer->generate('preview-theme', 'editorial'),
    ]);
    $request->setRouteResolver(fn (): Route => new Route('GET', '/admin/theme-preview/1/1/1', fn (): string => 'ok')->name('capell.admin.theme-preview'));

    $context = ThemePreviewContext::none();

    new ResolveThemePreviewContext($signer)->handle($request, function () use (&$context): string {
        $context = resolve(ThemePreviewContext::class);

        return 'ok';
    });

    expect($context->previewing)->toBeTrue()
        ->and($context->themeKey)->toBe('preview-theme')
        ->and($context->presetKey)->toBe('editorial');
});

it('clears theme preview context after the request finishes', function (): void {
    $signer = new ThemePreviewSigner('preview-test-secret');
    $request = Request::create('/admin/theme-preview/1/1/1', Symfony\Component\HttpFoundation\Request::METHOD_GET, [
        $signer->tokenParam() => $signer->generate('preview-theme', 'editorial'),
    ]);
    $request->setRouteResolver(fn (): Route => new Route('GET', '/admin/theme-preview/1/1/1', fn (): string => 'ok')->name('capell.admin.theme-preview'));

    new ResolveThemePreviewContext($signer)->handle($request, function (): string {
        $sharedContext = view()->getShared()['themePreviewContext'] ?? null;

        expect(resolve(ThemePreviewContext::class)->previewing)->toBeTrue()
            ->and($sharedContext)->toBeInstanceOf(ThemePreviewContext::class)
            ->and($sharedContext->previewing)->toBeTrue();

        return 'ok';
    });

    $sharedContext = view()->getShared()['themePreviewContext'] ?? null;

    expect(resolve(ThemePreviewContext::class)->previewing)->toBeFalse()
        ->and($sharedContext)->toBeInstanceOf(ThemePreviewContext::class)
        ->and($sharedContext->previewing)->toBeFalse();
});
