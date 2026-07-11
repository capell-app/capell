<?php

declare(strict_types=1);

use Capell\Core\Actions\GetComponentViewPathAction;
use Capell\Core\Exceptions\ComponentNotFoundException;
use Capell\Core\Facades\CapellCore;
use Illuminate\Support\Facades\File;

it('runs publish components command successfully', function (): void {
    artisanCommand('capell:publish-components')
        ->assertExitCode(0);
});

it('publishes all components and outputs info', function (): void {
    $components = ['TestComponent' => 'component/path.blade.php'];
    CapellCore::shouldReceive('getCoreComponents')->andReturn(['group' => $components]);
    $mock = Mockery::mock(GetComponentViewPathAction::class);
    $mock->shouldReceive('run')->andReturn('resources/views/vendor/test/component/path.blade.php');
    File::shouldReceive('put')->andReturn(true);
    File::shouldReceive('exists')->andReturn(false);

    artisanCommand('capell:publish-components')
        ->assertExitCode(0);
});

it('skips already published components', function (): void {
    $components = ['TestComponent' => 'component/path.blade.php'];
    CapellCore::shouldReceive('getCoreComponents')->andReturn(['group' => $components]);
    $mock = Mockery::mock(GetComponentViewPathAction::class);
    $mock->shouldReceive('run')->andReturn(resource_path('views/vendor/test/component/path.blade.php'));
    File::shouldReceive('put')->never();

    artisanCommand('capell:publish-components')
        ->assertExitCode(0);
});

it('handles ComponentNotFoundException gracefully', function (): void {
    $components = ['TestComponent' => 'component/path.blade.php'];
    CapellCore::shouldReceive('getCoreComponents')->andReturn(['group' => $components]);
    $mock = Mockery::mock(GetComponentViewPathAction::class);
    $mock->shouldReceive('run')->andThrow(new ComponentNotFoundException('not found'));
    File::shouldReceive('put')->never();

    artisanCommand('capell:publish-components')
        ->assertExitCode(0);
});

it('shows error if file cannot be written', function (): void {
    $components = ['TestComponent' => 'component/path.blade.php'];
    CapellCore::shouldReceive('getCoreComponents')->andReturn(['group' => $components]);
    $mock = Mockery::mock(GetComponentViewPathAction::class);
    $mock->shouldReceive('run')->andReturn('resources/views/vendor/test/component/path.blade.php');
    File::shouldReceive('put')->andReturn(false);

    artisanCommand('capell:publish-components')
        ->assertExitCode(0);
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
        artisanCommand('capell:publish-components')
            ->expectsOutputToContain('vendor-package::components.card')
            ->assertExitCode(0);

        expect(File::get($publishedPath))->toBe('<section>Package card</section>');
    } finally {
        File::deleteDirectory($packagePath);
        File::deleteDirectory(resource_path('views/vendor/vendor/publish-components-package'));
    }
});
