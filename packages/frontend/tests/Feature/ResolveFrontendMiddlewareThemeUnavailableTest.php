<?php

declare(strict_types=1);

use Capell\Core\Exceptions\ThemeNotFoundException;
use Capell\Frontend\Contracts\FrontendKernelInterface;
use Capell\Frontend\Data\FrontendBootstrapResult;
use Capell\Frontend\Http\Middleware\ResolveFrontendMiddleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

it('answers 503 with the theme diagnostic instead of a server error when no theme can be resolved', function (): void {
    app()->instance(FrontendKernelInterface::class, new class implements FrontendKernelInterface
    {
        public function bootstrap(Request $request): FrontendBootstrapResult
        {
            throw ThemeNotFoundException::unresolvedForRequest();
        }
    });

    $response = resolve(ResolveFrontendMiddleware::class)->handle(
        Request::create('/'),
        static fn (): Response => response('rendered'),
    );

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->toContain((string) __('capell-frontend::errors.theme_unavailable'))
        ->not->toContain('rendered');
});
