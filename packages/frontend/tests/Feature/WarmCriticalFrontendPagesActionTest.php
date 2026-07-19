<?php

declare(strict_types=1);

use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Actions\WarmCriticalFrontendPagesAction;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

it('warms every enabled default site homepage through the application kernel', function (): void {
    $siteDomain = SiteDomain::factory()
        ->enabled()
        ->default()
        ->create([
            'scheme' => 'https',
            'domain' => 'runtime-refresh.test',
            'path' => '/',
        ]);
    $kernel = Mockery::mock(Kernel::class);
    $requestedUrl = null;
    $kernel->shouldReceive('handle')
        ->once()
        ->andReturnUsing(function (Request $request) use (&$requestedUrl): Response {
            $requestedUrl = $request->fullUrl();

            return new Response('warm');
        });
    $kernel->shouldReceive('terminate')->once();

    (new WarmCriticalFrontendPagesAction($kernel))->warm();

    expect(rtrim((string) $requestedUrl, '/'))->toBe(rtrim($siteDomain->full_url, '/'));
});

it('fails the warm stage when a critical homepage is unhealthy', function (): void {
    SiteDomain::factory()
        ->enabled()
        ->default()
        ->create([
            'scheme' => 'https',
            'domain' => 'broken-runtime-refresh.test',
            'path' => '/',
        ]);
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('handle')->once()->andReturn(new Response('failed', 500));
    $kernel->shouldReceive('terminate')->once();

    expect(function () use ($kernel): void {
        (new WarmCriticalFrontendPagesAction($kernel))->warm();
    })
        ->toThrow(RuntimeException::class, 'returned HTTP 500');
});
