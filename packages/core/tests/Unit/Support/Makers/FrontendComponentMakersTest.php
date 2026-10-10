<?php

declare(strict_types=1);

use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Support\Makers\BuiltIn\AssetBladeComponentMaker;
use Capell\Core\Support\Makers\BuiltIn\PageBladeComponentMaker;
use Capell\Core\Support\Makers\BuiltIn\PageLivewireComponentMaker;
use Capell\Tests\Support\OwnedApplicationPaths;
use Illuminate\Contracts\View\Factory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Livewire\Component;

beforeEach(function (): void {
    $this->workspace = new OwnedApplicationPaths(app());
});

afterEach(function (): void {
    $this->workspace->restore();
});

it('previews a page blade component', function (): void {
    $preview = resolve(PageBladeComponentMaker::class)->preview(new MakerInputData(
        maker: 'core.page-blade-component',
        values: ['name' => 'Landing Hero'],
        dryRun: true,
        force: false,
        databaseWrites: false,
    ));

    $file = expectPresent(firstDataItem($preview->files));

    expect($file->path)->toBe(resource_path('views/components/page/landing-hero.blade.php'))
        ->and(Blade::render($file->contents, ['slot' => 'Visible page content']))->toContain('Visible page content')
        ->and(firstDataItem($preview->commands))->toBe('php artisan capell:make core.page-blade-component --name="landing-hero"')
        ->and(firstDataItem($preview->notes))->toContain('capell:cache-components');
});

it('previews a page livewire component class and view', function (): void {
    $preview = resolve(PageLivewireComponentMaker::class)->preview(new MakerInputData(
        maker: 'core.page-livewire-component',
        values: ['name' => 'Landing Hero'],
        dryRun: true,
        force: false,
        databaseWrites: false,
    ));

    expect(collect($preview->files)->pluck('path')->all())
        ->toContain(app_path('Livewire/LandingHero.php'))
        ->toContain(resource_path('views/livewire/landing-hero.blade.php'));

    $file = expectPresent(firstDataItem($preview->files));

    $files = new Filesystem;
    foreach ($preview->files as $generated) {
        $files->ensureDirectoryExists(dirname($generated->path));
        $files->put($generated->path, $generated->contents);
    }

    resolve(Factory::class)->addLocation(resource_path('views'));
    require $file->path;
    $class = 'App\\Livewire\\' . pathinfo((string) $file->path, PATHINFO_FILENAME);
    throw_unless(is_subclass_of($class, Component::class), RuntimeException::class, 'Generated component is not loadable.');
    $component = new $class;
    $render = [$component, 'render'];
    throw_unless(is_callable($render), RuntimeException::class, 'Generated component cannot render.');
    expect($component)->toBeInstanceOf(Component::class)
        ->and($render())->toContain('<section>');
});

it('previews an asset blade component', function (): void {
    $preview = resolve(AssetBladeComponentMaker::class)->preview(new MakerInputData(
        maker: 'core.asset-blade-component',
        values: ['name' => 'Hero Image'],
        dryRun: true,
        force: false,
        databaseWrites: false,
    ));

    $file = expectPresent(firstDataItem($preview->files));

    expect($file->path)->toBe(resource_path('views/components/asset/hero-image.blade.php'))
        ->and(Blade::render($file->contents, ['slot' => 'Visible asset content']))->toContain('Visible asset content')
        ->and(firstDataItem($preview->commands))->toBe('php artisan capell:make core.asset-blade-component --name="hero-image"')
        ->and(firstDataItem($preview->notes))->toContain('capell:cache-components');
});
