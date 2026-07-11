<?php

declare(strict_types=1);

it('uses registered theme chrome selects instead of free-text component inputs', function (): void {
    $headerFieldset = file_get_contents(dirname(__DIR__, 3) . '/src/Filament/Components/Forms/Theme/HeaderFieldset.php');
    $footerFieldset = file_get_contents(dirname(__DIR__, 3) . '/src/Filament/Components/Forms/Theme/FooterFieldset.php');

    expect($headerFieldset)->toContain("Select::make('header_file')")
        ->and($headerFieldset)->toContain('ThemeChromeRegistry::class')
        ->and($headerFieldset)->toContain('Rule::in(array_keys(resolve(ThemeChromeRegistry::class)->headerOptions()))')
        ->and($headerFieldset)->not->toContain("TextInput::make('header_file')")
        ->and($footerFieldset)->toContain("Select::make('footer_file')")
        ->and($footerFieldset)->toContain('ThemeChromeRegistry::class')
        ->and($footerFieldset)->toContain('Rule::in(array_keys(resolve(ThemeChromeRegistry::class)->footerOptions()))')
        ->and($footerFieldset)->not->toContain("TextInput::make('footer_file')");
});
