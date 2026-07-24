<?php

declare(strict_types=1);

use Capell\Core\Http\Middleware\EnsureMultiNodeUploadsUseSharedStorage;
use Capell\Core\Support\Hosting\MultiNodeTopologyGuard;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

function livewireUploadRequest(): Request
{
    $request = Request::create('/livewire/upload-file', Symfony\Component\HttpFoundation\Request::METHOD_POST);
    $route = new Route('POST', '/livewire/upload-file', static fn (): null => null);
    $route->name('livewire.upload-file');

    $request->setRouteResolver(static fn (): Route => $route);

    return $request;
}

it('refuses node-local Livewire temporary upload storage in a multi-node installation', function (): void {
    config()->set('capell.multi_node', true);
    config()->set('filesystems.default', 'local');
    config()->set('filesystems.disks.local.driver', 'local');
    config()->set('livewire.temporary_file_upload.disk');

    $middleware = new EnsureMultiNodeUploadsUseSharedStorage(new MultiNodeTopologyGuard);

    expect(fn (): mixed => $middleware->handle(livewireUploadRequest(), static fn (): string => 'next'))
        ->toThrow(RuntimeException::class, 'Livewire temporary file uploads cannot run while CAPELL_MULTI_NODE=true');
});

it('allows Livewire temporary uploads on shared storage in a multi-node installation', function (): void {
    config()->set('capell.multi_node', true);
    config()->set('filesystems.disks.uploads.driver', 's3');
    config()->set('livewire.temporary_file_upload.disk', 'uploads');

    $middleware = new EnsureMultiNodeUploadsUseSharedStorage(new MultiNodeTopologyGuard);

    expect($middleware->handle(livewireUploadRequest(), static fn (): string => 'next'))->toBe('next');
});

it('does not apply the upload storage guard to other web routes', function (): void {
    config()->set('capell.multi_node', true);
    config()->set('filesystems.default', 'local');
    config()->set('filesystems.disks.local.driver', 'local');

    $request = Request::create('/admin', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    $route = new Route('GET', '/admin', static fn (): null => null);
    $route->name('filament.admin.pages.dashboard');

    $request->setRouteResolver(static fn (): Route => $route);

    $middleware = new EnsureMultiNodeUploadsUseSharedStorage(new MultiNodeTopologyGuard);

    expect($middleware->handle($request, static fn (): string => 'next'))->toBe('next');
});
