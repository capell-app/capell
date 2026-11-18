<?php

declare(strict_types=1);

use Capell\Frontend\Filament\Settings\FrontendSettingsSchema;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\Frontend\Support\Themes\FrontendThemePreviewRenderer;
use Capell\Tests\Support\ArchitectureDependencies;

arch('keeps the Frontend package standalone', function (): void {
    ArchitectureDependencies::assertNoForbiddenUses(['Capell\Frontend'], [
        'Capell\Admin',
        'Capell\Address',
        'Capell\AIOrchestrator',
        'Capell\Blog',
        'Capell\Hero',
        'Capell\Layout',
    ], [
        FrontendSettingsSchema::class,
        FrontendServiceProvider::class,
        FrontendThemePreviewRenderer::class,
    ]);
});

arch('Frontend package must not reference FoundationTheme', function (): void {
    ArchitectureDependencies::assertNoForbiddenUses(['Capell\Frontend'], ['Capell\FoundationTheme'], []);
});
