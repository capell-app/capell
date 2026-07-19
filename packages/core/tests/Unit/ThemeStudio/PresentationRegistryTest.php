<?php

declare(strict_types=1);

use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\ThemeStudio\Contracts\PagePresentation;
use Capell\Core\ThemeStudio\Contracts\WidgetPresentation;
use Capell\Core\ThemeStudio\Theme\PagePresentationRegistry;
use Capell\Core\ThemeStudio\Theme\WidgetPresentationRegistry;

it('registers page presentations by page type and runtime', function (): void {
    $presentation = new class implements PagePresentation
    {
        public function pageType(): string
        {
            return 'standard';
        }

        public function runtime(): FrontendRuntime
        {
            return FrontendRuntime::Inertia;
        }

        public function component(): string
        {
            return 'Pages/Standard';
        }
    };

    $registry = new PagePresentationRegistry;
    $registry->register($presentation);

    expect($registry->get('standard', FrontendRuntime::Inertia))->toBe($presentation)
        ->and($registry->has('standard', FrontendRuntime::Inertia))->toBeTrue()
        ->and($registry->has('standard', FrontendRuntime::Blade))->toBeFalse();
});

it('keeps page presentations isolated by theme key', function (): void {
    $firstPresentation = new class implements PagePresentation
    {
        public function pageType(): string
        {
            return 'standard';
        }

        public function runtime(): FrontendRuntime
        {
            return FrontendRuntime::Inertia;
        }

        public function component(): string
        {
            return 'Themes/First/Standard';
        }
    };

    $secondPresentation = new class implements PagePresentation
    {
        public function pageType(): string
        {
            return 'standard';
        }

        public function runtime(): FrontendRuntime
        {
            return FrontendRuntime::Inertia;
        }

        public function component(): string
        {
            return 'Themes/Second/Standard';
        }
    };

    $registry = new PagePresentationRegistry;
    $registry->register($firstPresentation, 'first-theme');
    $registry->register($secondPresentation, 'second-theme');

    expect($registry->get('standard', FrontendRuntime::Inertia, 'first-theme'))->toBe($firstPresentation)
        ->and($registry->get('standard', FrontendRuntime::Inertia, 'second-theme'))->toBe($secondPresentation)
        ->and($registry->has('standard', FrontendRuntime::Inertia, 'missing-theme'))->toBeFalse();
});

it('falls back to global page presentations and lets theme presentations override them', function (): void {
    $globalPresentation = new class implements PagePresentation
    {
        public function pageType(): string
        {
            return 'standard';
        }

        public function runtime(): FrontendRuntime
        {
            return FrontendRuntime::Inertia;
        }

        public function component(): string
        {
            return 'Pages/Standard';
        }
    };

    $themePresentation = new class implements PagePresentation
    {
        public function pageType(): string
        {
            return 'standard';
        }

        public function runtime(): FrontendRuntime
        {
            return FrontendRuntime::Inertia;
        }

        public function component(): string
        {
            return 'Themes/Nexus/Standard';
        }
    };

    $registry = new PagePresentationRegistry;
    $registry->register($globalPresentation);
    $registry->register($themePresentation, 'nexus');

    expect($registry->get('standard', FrontendRuntime::Inertia, 'unknown-theme'))->toBe($globalPresentation)
        ->and($registry->get('standard', FrontendRuntime::Inertia, 'nexus'))->toBe($themePresentation);
});

it('registers widget presentations by widget type and runtime', function (): void {
    $presentation = new class implements WidgetPresentation
    {
        public function widgetType(): string
        {
            return 'hero';
        }

        public function runtime(): FrontendRuntime
        {
            return FrontendRuntime::Inertia;
        }

        public function component(): string
        {
            return 'Sections/Hero';
        }
    };

    $registry = new WidgetPresentationRegistry;
    $registry->register($presentation);

    expect($registry->get('hero', FrontendRuntime::Inertia))->toBe($presentation)
        ->and($registry->has('hero', FrontendRuntime::Inertia))->toBeTrue()
        ->and($registry->has('hero', FrontendRuntime::Livewire))->toBeFalse();
});

it('keeps widget presentations isolated by theme key', function (): void {
    $firstPresentation = new class implements WidgetPresentation
    {
        public function widgetType(): string
        {
            return 'hero';
        }

        public function runtime(): FrontendRuntime
        {
            return FrontendRuntime::Inertia;
        }

        public function component(): string
        {
            return 'Themes/First/Hero';
        }
    };

    $secondPresentation = new class implements WidgetPresentation
    {
        public function widgetType(): string
        {
            return 'hero';
        }

        public function runtime(): FrontendRuntime
        {
            return FrontendRuntime::Inertia;
        }

        public function component(): string
        {
            return 'Themes/Second/Hero';
        }
    };

    $registry = new WidgetPresentationRegistry;
    $registry->register($firstPresentation, 'first-theme');
    $registry->register($secondPresentation, 'second-theme');

    expect($registry->get('hero', FrontendRuntime::Inertia, 'first-theme'))->toBe($firstPresentation)
        ->and($registry->get('hero', FrontendRuntime::Inertia, 'second-theme'))->toBe($secondPresentation)
        ->and($registry->has('hero', FrontendRuntime::Inertia, 'missing-theme'))->toBeFalse();
});

it('falls back to global widget presentations and lets theme presentations override them', function (): void {
    $globalPresentation = new class implements WidgetPresentation
    {
        public function widgetType(): string
        {
            return 'hero';
        }

        public function runtime(): FrontendRuntime
        {
            return FrontendRuntime::Inertia;
        }

        public function component(): string
        {
            return 'Sections/Hero';
        }
    };

    $themePresentation = new class implements WidgetPresentation
    {
        public function widgetType(): string
        {
            return 'hero';
        }

        public function runtime(): FrontendRuntime
        {
            return FrontendRuntime::Inertia;
        }

        public function component(): string
        {
            return 'Themes/Nexus/Hero';
        }
    };

    $registry = new WidgetPresentationRegistry;
    $registry->register($globalPresentation);
    $registry->register($themePresentation, 'nexus');

    expect($registry->get('hero', FrontendRuntime::Inertia, 'unknown-theme'))->toBe($globalPresentation)
        ->and($registry->get('hero', FrontendRuntime::Inertia, 'nexus'))->toBe($themePresentation);
});
