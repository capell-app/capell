<?php

declare(strict_types=1);

use Capell\Core\Actions\GetComponentViewPathAction;
use Capell\Core\Exceptions\ComponentNotFoundException;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Components\ComponentRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

it('fails unresolved default components in a core-only host', function (): void {
    $exitCode = Artisan::call('capell:publish-components');
    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('0 published, 0 skipped, 5 failed');
});

it('reports failed component batches without claiming completion', function (bool $includeSuccessfulComponent): void {
    $packagePath = storage_path('framework/testing/component-failure-' . uniqid());
    $viewPath = $packagePath . '/resources/views/components/card.blade.php';
    $publishedDirectory = resource_path('views/vendor/vendor/component-failure');
    File::ensureDirectoryExists(dirname($viewPath));
    File::put($viewPath, '<section>Published card</section>');
    CapellCore::clearPackages();
    CapellCore::registerPackage('vendor/component-failure', path: $packagePath);
    $components = ['Broken card' => 'broken-card'];
    if ($includeSuccessfulComponent) {
        $components['Working card'] = 'working-card';
    }

    CapellCore::partialMock()->shouldReceive('getCoreComponents')->andReturn(['Blocks' => $components]);
    GetComponentViewPathAction::shouldRun()->andReturnUsing(function (string $component) use ($viewPath): string {
        throw_if($component === 'broken-card', RuntimeException::class, 'Cannot read the required component source.');

        return $viewPath;
    });

    try {
        artisanCommand('capell:publish-components')
            ->expectsOutputToContain('Broken card: Cannot read the required component source.')
            ->expectsOutputToContain(($includeSuccessfulComponent ? '1' : '0') . ' published, 0 skipped, 1 failed')
            ->doesntExpectOutputToContain('Finished publishing components.')
            ->assertExitCode(1);

        expect(File::exists($publishedDirectory . '/components/card.blade.php'))->toBe($includeSuccessfulComponent);
    } finally {
        File::deleteDirectory($packagePath);
        File::deleteDirectory($publishedDirectory);
    }
})->with([false, true]);

it('treats an already published component as an intentional skip', function (): void {
    CapellCore::partialMock()->shouldReceive('getCoreComponents')->andReturn(['Blocks' => ['Card' => 'published-card']]);
    GetComponentViewPathAction::shouldRun()->once()->with('published-card')->andReturn(resource_path('views/components/card.blade.php'));

    artisanCommand('capell:publish-components')
        ->expectsOutputToContain('0 published, 1 skipped, 0 failed')
        ->assertExitCode(0);
});

it('fails when a required component cannot be found', function (): void {
    CapellCore::partialMock()->shouldReceive('getCoreComponents')->andReturn(['Blocks' => ['Missing card' => 'missing-card']]);
    GetComponentViewPathAction::shouldRun()->once()->with('missing-card')->andThrow(new ComponentNotFoundException('Component view not found.'));

    artisanCommand('capell:publish-components')
        ->expectsOutputToContain('Missing card: Component view not found.')
        ->expectsOutputToContain('0 published, 0 skipped, 1 failed')
        ->assertExitCode(1);
});

it('publishes registered package components to the host vendor view path', function (): void {
    $packagePath = storage_path('framework/testing/publish-components-package');
    $viewPath = $packagePath . '/resources/views/components/card.blade.php';
    $publishedPath = resource_path('views/vendor/vendor/publish-components-package/components/card.blade.php');

    File::deleteDirectory($packagePath);
    File::delete($publishedPath);
    File::ensureDirectoryExists(dirname($viewPath));
    File::put($viewPath, '<section>Package card</section>');

    CapellCore::clearPackages();
    app()->instance(ComponentRegistry::class, new ComponentRegistry);
    CapellCore::registerPackage('vendor/publish-components-package', path: $packagePath);
    CapellCore::registerComponent('Blocks', 'Package card', 'vendor-package::components.card');

    app()->bind(GetComponentViewPathAction::class, fn (): object => new readonly class($viewPath)
    {
        public function __construct(private string $viewPath) {}

        public function handle(string $component): string
        {
            expect($component)->toBe('vendor-package::components.card');

            return $this->viewPath;
        }
    });

    try {
        $exitCode = Artisan::call('capell:publish-components');
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);
        expect($output)->toContain('vendor-package::components.card');

        expect(File::get($publishedPath))->toBe('<section>Package card</section>');
    } finally {
        File::deleteDirectory($packagePath);
        File::deleteDirectory(resource_path('views/vendor/vendor/publish-components-package'));
    }
});

it('reports filesystem publication failures', function (bool $missingSource): void {
    $packagePath = storage_path('framework/testing/component-filesystem-' . uniqid());
    $viewPath = $packagePath . '/resources/views/components/card.blade.php';
    $publishedDirectory = resource_path('views/vendor/vendor/component-filesystem');
    File::ensureDirectoryExists(dirname($viewPath));
    File::ensureDirectoryExists($publishedDirectory);

    if (! $missingSource) {
        File::put($viewPath, '<section>Card</section>');
        File::put($publishedDirectory . '/components', 'A file blocks the destination directory.');
    }

    CapellCore::clearPackages();
    CapellCore::registerPackage('vendor/component-filesystem', path: $packagePath);
    CapellCore::partialMock()->shouldReceive('getCoreComponents')->andReturn(['Blocks' => ['Card' => 'card']]);
    GetComponentViewPathAction::shouldRun()->once()->andReturn($viewPath);

    try {
        expect(Artisan::call('capell:publish-components'))->toBe(1)
            ->and(Artisan::output())->toContain('Card:', '0 published, 0 skipped, 1 failed')
            ->not->toContain('Finished publishing components.');
    } finally {
        File::deleteDirectory($packagePath);
        File::deleteDirectory($publishedDirectory);
    }
})->with([false, true]);
