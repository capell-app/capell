<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\FrontendKernelInterface;
use Capell\Frontend\Data\ErrorData;
use Capell\Frontend\Data\FrontendBootstrapResult;
use Capell\Frontend\Http\Middleware\ResolveFrontendMiddleware;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

it('guards path fallback blade views before returning from frontend resolution middleware', function (): void {
    $viewDirectory = resource_path('views');
    $viewPath = $viewDirectory . '/middleware-fallback-guard.blade.php';

    File::ensureDirectoryExists($viewDirectory);
    File::put($viewPath, '<div data-capell-authoring="page:1">Unsafe fallback</div>');

    $request = Request::create('/middleware-fallback-guard');
    app()->instance('request', $request);

    try {
        resolveFallbackMiddleware()->handle($request, fn (): Response => new Response('next'));
    } finally {
        File::delete($viewPath);
    }
})->throws(RuntimeException::class, 'Public HTML contains an authoring marker.');

it('guards named route fallback blade views before returning from frontend resolution middleware', function (): void {
    $viewDirectory = resource_path('views/named/middleware');
    $viewPath = $viewDirectory . '/guard.blade.php';
    $request = Request::create('/middleware-named-fallback-guard');
    $request->setRouteResolver(fn (): Route => new Route('GET', '/middleware-named-fallback-guard', [])->name('named.middleware.guard'));

    File::ensureDirectoryExists($viewDirectory);
    File::put($viewPath, '<a href="/admin/pages/1/edit?signature=abc">Edit</a>');

    app()->instance('request', $request);

    try {
        resolveFallbackMiddleware()->handle($request, fn (): Response => new Response('next'));
    } finally {
        File::delete($viewPath);
    }
})->throws(RuntimeException::class, 'Public HTML contains a signed admin URL.');

function resolveFallbackMiddleware(): ResolveFrontendMiddleware
{
    return new ResolveFrontendMiddleware(
        new class implements FrontendKernelInterface
        {
            public function bootstrap(Request $request): FrontendBootstrapResult
            {
                return new FrontendBootstrapResult(
                    error: new ErrorData(Response::HTTP_NOT_FOUND, 'Page not found'),
                );
            }
        },
        resolve(FrontendState::class),
    );
}
