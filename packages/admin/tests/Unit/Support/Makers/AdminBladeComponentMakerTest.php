<?php

declare(strict_types=1);

use Capell\Admin\Contracts\RegistryInspectorInterface;
use Capell\Admin\Data\Diagnostics\RegistrySourceData;
use Capell\Admin\Support\Makers\AdminBladeComponentMaker;
use Capell\Admin\Support\Makers\ComponentSourceResolver;
use Capell\Core\Data\Makers\MakerInputData;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;

it('previews a host app blade component from the blank stub', function (): void {
    app()->instance(RegistryInspectorInterface::class, new class implements RegistryInspectorInterface
    {
        public function configurators(?string $configuratorType = null): Collection
        {
            return collect();
        }

        public function components(?string $componentType = null): Collection
        {
            return collect();
        }

        public function blocks(): Collection
        {
            return collect();
        }

        public function widgets(): Collection
        {
            return collect();
        }
    });

    $preview = resolve(AdminBladeComponentMaker::class)->preview(new MakerInputData(
        maker: 'admin.component',
        values: [
            'type' => 'Widget',
            'name' => 'Hero Card',
            'source' => ComponentSourceResolver::BLANK_SOURCE_KEY,
        ],
        dryRun: true,
        force: false,
        databaseWrites: false,
    ));
    $file = expectPresent(firstDataItem($preview->files));

    expect($preview->maker)->toBe('admin.component')
        ->and($preview->files)->toHaveCount(1)
        ->and($file->path)->toBe(resource_path('views/components/widget/hero-card.blade.php'))
        ->and($file->contents)->toContain('<section>')
        ->and(firstDataItem($preview->commands))->toBe('php artisan capell:make admin.component --type=Widget --name="hero-card"')
        ->and(firstDataItem($preview->notes))->toContain('capell:cache-components');
});

it('copies an existing component view when a source is selected', function (): void {
    $sourcePath = '/packages/layout/resources/views/components/widget/hero.blade.php';
    $targetPath = resource_path('views/components/widget/hero-clone.blade.php');

    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('exists')->once()->with($sourcePath)->andReturnTrue();
    $filesystem->shouldReceive('get')->once()->with($sourcePath)->andReturn('<article>Existing component</article>');
    $filesystem->shouldReceive('exists')->once()->with($targetPath)->andReturnFalse();

    app()->instance(Filesystem::class, $filesystem);

    app()->instance(RegistryInspectorInterface::class, new readonly class($sourcePath) implements RegistryInspectorInterface
    {
        public function __construct(private string $sourcePath) {}

        public function configurators(?string $configuratorType = null): Collection
        {
            return collect();
        }

        public function components(?string $componentType = null): Collection
        {
            /** @var Collection<int|string, mixed> $components */
            $components = collect([
                new RegistrySourceData(
                    key: 'capell.widget.hero',
                    label: 'Hero',
                    kind: 'component',
                    class: null,
                    view: 'capell.widget.hero',
                    path: $this->sourcePath,
                    sourcePackage: 'package',
                    sourceMode: 'registered',
                    cachePath: null,
                    statePath: $componentType,
                    flow: collect(),
                ),
            ]);

            return $components;
        }

        public function blocks(): Collection
        {
            return collect();
        }

        public function widgets(): Collection
        {
            return collect();
        }
    });

    $preview = resolve(AdminBladeComponentMaker::class)->preview(new MakerInputData(
        maker: 'admin.component',
        values: [
            'type' => 'Widget',
            'name' => 'Hero Clone',
            'source' => 'capell.widget.hero',
        ],
        dryRun: true,
        force: false,
        databaseWrites: false,
    ));
    $file = expectPresent(firstDataItem($preview->files));

    expect($file->path)->toBe($targetPath)
        ->and($file->contents)->toBe('<article>Existing component</article>');
});

it('writes a created component through the filesystem service without touching real files', function (): void {
    $targetPath = resource_path('views/components/widget/hero-card.blade.php');

    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('exists')->once()->with($targetPath)->andReturnFalse();
    $filesystem->shouldReceive('ensureDirectoryExists')->once()->with(dirname($targetPath))->andReturnNull();
    $filesystem->shouldReceive('put')
        ->once()
        ->with($targetPath, Mockery::on(fn (string $contents): bool => str_contains($contents, '<section>')))
        ->andReturn(32);

    app()->instance(Filesystem::class, $filesystem);

    $result = resolve(AdminBladeComponentMaker::class)->run(new MakerInputData(
        maker: 'admin.component',
        values: [
            'type' => 'Widget',
            'name' => 'Hero Card',
            'source' => ComponentSourceResolver::BLANK_SOURCE_KEY,
        ],
        dryRun: false,
        force: false,
        databaseWrites: false,
    ));
    $file = expectPresent(firstDataItem($result->files));

    expect($result->successful)->toBeTrue()
        ->and($file->path)->toBe($targetPath)
        ->and($file->operation)->toBe('create')
        ->and($file->exists)->toBeTrue();
});
