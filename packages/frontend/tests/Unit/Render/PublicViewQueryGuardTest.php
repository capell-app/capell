<?php

declare(strict_types=1);

use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\FrontendContext;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Enums\FrontendRenderAudience;
use Capell\Frontend\Support\Render\PublicViewQueryGuard;
use Illuminate\Container\Container as LaravelContainer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

it('guards public rendering when no explicit setting is configured', function (): void {
    config([
        'capell-frontend.public_view_query_guard.enabled' => null,
        'capell-frontend.public_view_query_guard.mode' => 'exception',
    ]);

    expect(fn () => resolve(PublicViewQueryGuard::class)->guard(
        new FrontendRenderContextData(null, null, null, null, null),
        fn (): array => DB::select('select 1 as default_guard_probe'),
    ))->toThrow(RuntimeException::class, 'Public Blade rendering executed 1 database query');
});

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

it('isolates query capture between application scopes without registering more listeners', function (): void {
    config([
        'capell-frontend.public_view_query_guard.enabled' => true,
        'capell-frontend.public_view_query_guard.mode' => 'log',
    ]);

    $dispatcher = DB::connection()->getEventDispatcher();
    $listenerCount = count($dispatcher?->getListeners(QueryExecuted::class) ?? []);
    $reports = [];

    Log::shouldReceive('warning')
        ->twice()
        ->withArgs(function (string $message, array $context) use (&$reports): bool {
            $reports[] = $context;

            return $message === 'capell-frontend: public Blade rendering executed database queries';
        });

    $firstPage = (new Page)->setAttribute('id', 101);
    $firstLayout = (new Layout)->setAttribute('id', 102);
    $firstTheme = (new Theme)->setAttribute('id', 103);
    $secondPage = (new Page)->setAttribute('id', 201);
    $secondLayout = (new Layout)->setAttribute('id', 202);
    $secondTheme = (new Theme)->setAttribute('id', 203);

    $firstGuard = resolve(PublicViewQueryGuard::class);
    $firstResult = $firstGuard->guard(
        new FrontendRenderContextData($firstPage, null, null, $firstLayout, $firstTheme),
        fn (): string => DB::scalar('select "operation_a"'),
    );

    app()->forgetScopedInstances();

    $secondGuard = resolve(PublicViewQueryGuard::class);
    $noQueryResult = $secondGuard->guard(
        new FrontendRenderContextData($secondPage, null, null, $secondLayout, $secondTheme),
        fn (): string => 'operation_b_without_query',
    );
    $secondResult = $secondGuard->guard(
        new FrontendRenderContextData($secondPage, null, null, $secondLayout, $secondTheme),
        fn (): string => DB::scalar('select "operation_b"'),
    );

    expect($firstResult)->toBe('operation_a')
        ->and($noQueryResult)->toBe('operation_b_without_query')
        ->and($secondResult)->toBe('operation_b')
        ->and($secondGuard)->not->toBe($firstGuard)
        ->and($reports)->toHaveCount(2)
        ->and($reports[0]['queries'])->toHaveCount(1)
        ->and($reports[0]['queries'][0]['sql_shape'])->toContain('operation_a')
        ->and($reports[0]['page_id'])->toBe(101)
        ->and($reports[0]['layout_id'])->toBe(102)
        ->and($reports[0]['theme_id'])->toBe(103)
        ->and($reports[1]['queries'])->toHaveCount(1)
        ->and($reports[1]['queries'][0]['sql_shape'])->toContain('operation_b')
        ->and($reports[1]['queries'][0]['sql_shape'])->not->toContain('operation_a')
        ->and($reports[1]['page_id'])->toBe(201)
        ->and($reports[1]['layout_id'])->toBe(202)
        ->and($reports[1]['theme_id'])->toBe(203)
        ->and(count($dispatcher?->getListeners(QueryExecuted::class) ?? []))->toBe($listenerCount);
});

it('keeps nested guards active and restores state after exceptions', function (): void {
    config([
        'capell-frontend.public_view_query_guard.enabled' => true,
        'capell-frontend.public_view_query_guard.mode' => 'log',
    ]);

    $guard = resolve(PublicViewQueryGuard::class);
    $context = new FrontendRenderContextData(null, null, null, null, null);

    expect(fn (): mixed => $guard->guard($context, function () use ($guard, $context): never {
        expect($guard->isActive())->toBeTrue();

        try {
            $guard->guard($context, function () use ($guard): never {
                expect($guard->isActive())->toBeTrue();

                throw new RuntimeException('nested render failed');
            });
        } catch (RuntimeException $runtimeException) {
            expect($runtimeException->getMessage())->toBe('nested render failed')
                ->and($guard->isActive())->toBeTrue();
        }

        throw new RuntimeException('outer render failed');
    }))->toThrow(RuntimeException::class, 'outer render failed');

    expect($guard->isActive())->toBeFalse();
});

it('resolves the query guard from the current container when a query is dispatched', function (): void {
    config([
        'capell-frontend.public_view_query_guard.enabled' => true,
        'capell-frontend.public_view_query_guard.mode' => 'log',
    ]);

    $baseApplication = app();
    $baseGuard = resolve(PublicViewQueryGuard::class);
    $sandbox = new LaravelContainer;
    $sandbox->instance('config', $baseApplication->make(Repository::class));
    $sandbox->scoped(PublicViewQueryGuard::class);

    $sandboxGuard = $sandbox->make(PublicViewQueryGuard::class);
    $reports = [];

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use (&$reports): bool {
            $reports[] = $context;

            return $message === 'capell-frontend: public Blade rendering executed database queries';
        });

    $baseResult = $baseGuard->guard(
        new FrontendRenderContextData((new Page)->setAttribute('id', 301), null, null, null, null),
        function () use ($baseApplication, $sandbox, $sandboxGuard): string {
            LaravelContainer::setInstance($sandbox);

            try {
                return $sandboxGuard->guard(
                    new FrontendRenderContextData((new Page)->setAttribute('id', 401), null, null, null, null),
                    fn (): string => DB::scalar('select "sandbox_operation"'),
                );
            } finally {
                LaravelContainer::setInstance($baseApplication);
            }
        },
    );

    expect($baseResult)->toBe('sandbox_operation')
        ->and($reports)->toHaveCount(1)
        ->and($reports[0]['page_id'])->toBe(401)
        ->and($reports[0]['queries'])->toHaveCount(1)
        ->and($reports[0]['queries'][0]['sql_shape'])->toContain('sandbox_operation')
        ->and($baseGuard->isActive())->toBeFalse()
        ->and($sandboxGuard->isActive())->toBeFalse();
});
