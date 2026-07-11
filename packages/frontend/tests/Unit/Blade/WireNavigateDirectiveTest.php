<?php

declare(strict_types=1);

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Illuminate\Support\Facades\Blade;

it('does not render wire navigation for blade-only pages', function (): void {
    app()->instance(
        FrontendContextReader::class,
        wireNavigateTestContext(FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly)),
    );

    expect(Blade::render('<a href="/" @wireNavigate>Home</a>'))
        ->not->toContain('wire:navigate');
});

it('renders wire navigation for full livewire pages', function (): void {
    app()->instance(
        FrontendContextReader::class,
        wireNavigateTestContext(FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::FullLivewire)),
    );

    expect(Blade::render('<a href="/" @wireNavigate>Home</a>'))
        ->toContain('wire:navigate');
});

function wireNavigateTestContext(FrontendRuntimeManifestData $manifest): FrontendContextReader
{
    return new readonly class($manifest) implements FrontendContextReader
    {
        public function __construct(
            private FrontendRuntimeManifestData $manifest,
        ) {}

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
            return null;
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
            return $this->manifest;
        }
    };
}
