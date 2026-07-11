<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\FrontendContext;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Enums\FrontendRenderAudience;
use Capell\Frontend\Support\Render\PublicViewQueryGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

it('throws when public blade rendering executes database queries', function (): void {
    config([
        'capell-frontend.public_view_query_guard.enabled' => true,
        'capell-frontend.public_view_query_guard.mode' => 'exception',
    ]);

    expect(fn () => resolve(PublicViewQueryGuard::class)->guard(
        new FrontendRenderContextData(null, null, null, null, null),
        fn (): array => DB::select('select 1 as guard_probe'),
    ))->toThrow(RuntimeException::class, 'CAPELL_FRONTEND_PUBLIC_VIEW_QUERY_GUARD_ENABLED=false');
});

it('reports the blade view that executed a public render query', function (): void {
    config([
        'capell-frontend.public_view_query_guard.enabled' => true,
        'capell-frontend.public_view_query_guard.mode' => 'exception',
    ]);

    $viewDirectory = resource_path('views/query-guard');
    $viewPath = $viewDirectory . '/origin.blade.php';

    File::ensureDirectoryExists($viewDirectory);
    File::put($viewPath, '@php(DB::select("select 1 as blade_guard_probe"))');

    expect(fn () => resolve(PublicViewQueryGuard::class)->guard(
        new FrontendRenderContextData(null, null, null, null, null),
        fn (): string => View::make('query-guard.origin')->render(),
    ))->toThrow(RuntimeException::class, $viewPath);
});

it('can log public blade rendering queries without blocking the response', function (): void {
    config([
        'capell-frontend.public_view_query_guard.enabled' => true,
        'capell-frontend.public_view_query_guard.mode' => 'log',
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'capell-frontend: public Blade rendering executed database queries'
            && count($context['queries'] ?? []) === 1);

    $result = resolve(PublicViewQueryGuard::class)->guard(
        new FrontendRenderContextData(null, null, null, null, null),
        fn (): string => DB::scalar('select "rendered"'),
    );

    expect($result)->toBe('rendered');
});

it('does not guard preview audience rendering', function (): void {
    config([
        'capell-frontend.public_view_query_guard.enabled' => true,
        'capell-frontend.public_view_query_guard.mode' => 'exception',
    ]);

    $context = new FrontendContext(null, null, null, null, null, [], null);
    $context->setFrontendData('renderAudience', FrontendRenderAudience::Preview);

    app()->instance(FrontendContextReader::class, $context);

    $result = resolve(PublicViewQueryGuard::class)->guard(
        new FrontendRenderContextData(null, null, null, null, null),
        fn (): string => DB::scalar('select "preview"'),
    );

    expect($result)->toBe('preview');
});
