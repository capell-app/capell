<?php

declare(strict_types=1);

use Capell\Frontend\Http\Middleware\RejectReservedFrontendPaths;
use Capell\Frontend\Support\Routing\ReservedFrontendPathRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

it('does not match internal livewire update urls as frontend pages', function (string $url): void {
    $config = require __DIR__ . '/../../config/capell-frontend.php';

    expect(preg_match('#' . $config['route']['url_regex'] . '#', $url))->toBe(0);
})->with([
    'standard update endpoint' => 'livewire/update',
    'fingerprinted update endpoint' => 'livewire-6df3745a/update',
    'admin edit page' => 'admin/pages/125/edit',
    'installer entrypoint' => 'install',
    'installer progress' => 'install/progress/install-123',
    'public javascript asset' => 'vendor/capell-search/search-modal.js',
    'public css asset' => 'vendor/capell-search/search-modal.css',
    'public image asset' => 'vendor/capell-search/search-modal.png',
]);

it('still matches frontend page urls', function (string $url): void {
    $config = require __DIR__ . '/../../config/capell-frontend.php';

    expect(preg_match('#' . $config['route']['url_regex'] . '#', $url))->toBe(1);
})->with([
    'top-level page' => 'about',
    'nested page' => 'services/web-design',
    'top-level html page' => 'about.html',
    'nested html page' => 'services/web-design.html',
]);

it('rejects reserved frontend paths before resolving public themes', function (string $reservedPath): void {
    resolve(ReservedFrontendPathRegistry::class)->reservePrefix($reservedPath);

    expect(fn () => resolve(RejectReservedFrontendPaths::class)->handle(
        Request::create('/' . $reservedPath . '/extensions/marketplace'),
        static fn (): never => throw new LogicException('Reserved request continued through the frontend route.'),
    ))->toThrow(NotFoundHttpException::class);
})->with([
    'default admin path' => 'admin',
    'custom admin path' => '123',
    'package-owned path' => 'package-webhook',
]);

it('rejects the configured admin path before resolving public themes', function (): void {
    config()->set('capell-admin.path', 'admin');

    expect(resolve(ReservedFrontendPathRegistry::class)->isReserved('admin/pages/125/edit'))->toBeTrue();

    $this->get('/admin/pages/125/edit')
        ->assertNotFound()
        ->assertDontSee('Page not found')
        ->assertDontSee('Privacy-friendly analytics');
});
