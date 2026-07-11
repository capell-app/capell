<?php

declare(strict_types=1);

use Capell\Core\Enums\RuntimeContextEnum;
use Capell\Core\Support\PackageRegistry\RuntimeContextResolver;
use Illuminate\Support\Facades\Config;

it('resolves console context when running in console', function (): void {
    $resolver = new RuntimeContextResolver;

    // The test suite runs in cli, so this will always be Console.
    expect($resolver->resolve())->toBe(RuntimeContextEnum::Console);
});

it('resolves admin context for admin-path requests', function (): void {
    Config::set('filament.path', 'admin');
    $resolver = new RuntimeContextResolver;
    $context = $resolver->resolveFromPath('admin/pages');

    expect($context)->toBe(RuntimeContextEnum::Admin);
});

it('resolves admin context from the configured Capell admin path', function (): void {
    Config::set('capell-admin.path', 'cms');
    Config::set('filament.path', 'admin');

    $resolver = new RuntimeContextResolver;

    expect($resolver->resolveFromPath('cms/pages'))->toBe(RuntimeContextEnum::Admin)
        ->and($resolver->resolveFromPath('admin/pages'))->toBe(RuntimeContextEnum::Frontend);
});

it('resolves admin context for root paths on a configured admin domain', function (): void {
    Config::set('capell-admin.domain', 'admin.example.test');
    Config::set('capell-admin.path', '/');
    Config::set('filament.path', 'admin');

    $resolver = new RuntimeContextResolver;

    expect($resolver->resolveFromPath('', 'admin.example.test'))->toBe(RuntimeContextEnum::Admin)
        ->and($resolver->resolveFromPath('pages', 'admin.example.test'))->toBe(RuntimeContextEnum::Admin)
        ->and($resolver->resolveFromPath('', 'www.example.test'))->toBe(RuntimeContextEnum::Frontend);
});

it('resolves auth context for auth-path requests', function (): void {
    Config::set('filament.path', 'admin');
    $resolver = new RuntimeContextResolver;

    expect($resolver->resolveFromPath('register'))->toBe(RuntimeContextEnum::Auth)
        ->and($resolver->resolveFromPath('login'))->toBe(RuntimeContextEnum::Auth)
        ->and($resolver->resolveFromPath('reset-password/token'))->toBe(RuntimeContextEnum::Auth)
        ->and($resolver->resolveFromPath('email/verify/1/hash'))->toBe(RuntimeContextEnum::Auth);
});

it('resolves frontend context for non-admin requests', function (): void {
    Config::set('filament.path', 'admin');
    $resolver = new RuntimeContextResolver;
    $context = $resolver->resolveFromPath('blog/my-post');

    expect($context)->toBe(RuntimeContextEnum::Frontend);
});

it('does not resolve frontend paths with the admin prefix as admin context', function (): void {
    Config::set('filament.path', 'admin');
    $resolver = new RuntimeContextResolver;

    expect($resolver->resolveFromPath('administrator'))->toBe(RuntimeContextEnum::Frontend)
        ->and($resolver->resolveFromPath('administer/pages'))->toBe(RuntimeContextEnum::Frontend);
});

it('resolves frontend context for root path', function (): void {
    Config::set('filament.path', 'admin');
    $resolver = new RuntimeContextResolver;
    $context = $resolver->resolveFromPath('');

    expect($context)->toBe(RuntimeContextEnum::Frontend);
});

it('uses configured auth path globs', function (): void {
    Config::set('filament.path', 'admin');
    Config::set('capell.runtime.auth_paths', ['account/*']);

    $resolver = new RuntimeContextResolver;

    expect($resolver->resolveFromPath('account/register'))->toBe(RuntimeContextEnum::Auth)
        ->and($resolver->resolveFromPath('register'))->toBe(RuntimeContextEnum::Frontend);
});
