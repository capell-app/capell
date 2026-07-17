<?php

declare(strict_types=1);

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Http\View\RenderingStrategyViewComposer;
use Illuminate\View\View;

it('leaves existing runtime manifests untouched during public view composition', function (): void {
    $manifest = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $view = Mockery::mock(View::class);

    $view->shouldReceive('getData')
        ->once()
        ->andReturn(['runtimeManifest' => $manifest]);
    $view->shouldNotReceive('with');

    resolve(RenderingStrategyViewComposer::class)->compose($view);
});

it('adds a livewire runtime manifest from the active frontend page strategy', function (): void {
    $page = Page::factory()->make([
        'meta' => ['rendering_strategy' => RenderingStrategyEnum::FullLivewire->value],
    ]);

    app()->instance(FrontendContextReader::class, new readonly class($page) implements FrontendContextReader
    {
        public function __construct(private Pageable $page) {}

        public function site(): ?Site
        {
            return null;
        }

        public function language(): ?Language
        {
            return null;
        }

        public function page(): Pageable
        {
            return $this->page;
        }

        public function layout(): ?Layout
        {
            return null;
        }

        public function theme(): ?Theme
        {
            return null;
        }

        public function params(): array
        {
            return [];
        }

        public function slug(): ?string
        {
            return null;
        }

        public function isError(): bool
        {
            return false;
        }

        public function setFrontendData(string $key, mixed $value): self
        {
            return $this;
        }

        public function getFrontendData(?string $key = null): mixed
        {
            return null;
        }
    });

    $view = Mockery::mock(View::class);
    $view->shouldReceive('getData')->once()->andReturn([]);
    $view->shouldReceive('with')
        ->once()
        ->with('runtimeManifest', Mockery::on(
            fn (FrontendRuntimeManifestData $manifest): bool => $manifest->usesLivewire,
        ))
        ->andReturnSelf();
    $view->shouldReceive('with')
        ->once()
        ->with('livewireEnabled', true)
        ->andReturnSelf();

    resolve(RenderingStrategyViewComposer::class)->compose($view);
});

it('falls back to blade only view data when frontend context cannot resolve a page', function (): void {
    app()->instance(FrontendContextReader::class, new class implements FrontendContextReader
    {
        public function site(): ?Site
        {
            return null;
        }

        public function language(): ?Language
        {
            return null;
        }

        public function page(): ?Pageable
        {
            throw new RuntimeException('No frontend page has been resolved.');
        }

        public function layout(): ?Layout
        {
            return null;
        }

        public function theme(): ?Theme
        {
            return null;
        }

        public function params(): array
        {
            return [];
        }

        public function slug(): ?string
        {
            return null;
        }

        public function isError(): bool
        {
            return false;
        }

        public function setFrontendData(string $key, mixed $value): self
        {
            return $this;
        }

        public function getFrontendData(?string $key = null): mixed
        {
            return null;
        }
    });

    $view = Mockery::mock(View::class);
    $view->shouldReceive('getData')->once()->andReturn([]);
    $view->shouldReceive('with')
        ->once()
        ->with('runtimeManifest', Mockery::on(
            fn (FrontendRuntimeManifestData $manifest): bool => $manifest->usesLivewire === false,
        ))
        ->andReturnSelf();
    $view->shouldReceive('with')
        ->once()
        ->with('livewireEnabled', false)
        ->andReturnSelf();

    resolve(RenderingStrategyViewComposer::class)->compose($view);
});
