<?php

declare(strict_types=1);

use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Frontend\Filament\Settings\FrontendSettingsSchema;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\Frontend\Support\Themes\FrontendThemePreviewRenderer;

it('Site package to be standalone')
    ->expect('Capell\Frontend')
    ->not()->toUse(['Capell\Admin', 'Capell\Address', 'Capell\AIOrchestrator', 'Capell\Blog', 'Capell\Hero', 'Capell\Layout'])
    ->ignoring([
        FrontendSettingsSchema::class,
        FrontendServiceProvider::class,
        FrontendThemePreviewRenderer::class,
    ]);

it('Frontend package must not reference FoundationTheme')
    ->expect('Capell\Frontend')
    ->not()->toUse(['Capell\FoundationTheme']);

arch('frontend providers contribute settings through the package surface registrar')
    ->expect('Capell\Frontend\Providers')
    ->not()->toUse(SettingsSchemaRegistry::class);
