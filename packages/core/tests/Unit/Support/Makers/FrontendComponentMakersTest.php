<?php

declare(strict_types=1);

use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Support\Makers\BuiltIn\AssetBladeComponentMaker;
use Capell\Core\Support\Makers\BuiltIn\PageBladeComponentMaker;
use Capell\Core\Support\Makers\BuiltIn\PageLivewireComponentMaker;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('exists')->zeroOrMoreTimes()->andReturn(false);

    app()->instance(Filesystem::class, $filesystem);
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
        ->and($file->contents)->toContain('<section>')
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

    expect($file->contents)
        ->toContain('declare(strict_types=1);')
        ->toContain('class LandingHero extends Component')
        ->toContain("view('livewire.landing-hero')");
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
        ->and($file->contents)->toContain('<figure>')
        ->and(firstDataItem($preview->commands))->toBe('php artisan capell:make core.asset-blade-component --name="hero-image"')
        ->and(firstDataItem($preview->notes))->toContain('capell:cache-components');
});
