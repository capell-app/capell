<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\Theme\FooterFieldset;
use Capell\Admin\Filament\Components\Forms\Theme\HeaderFieldset;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Core\Support\Themes\ThemeChromeRegistry;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

it('uses registered theme chrome selects instead of free-text component inputs', function (): void {
    $registry = resolve(ThemeChromeRegistry::class);
    $registry->registerHeader('vendor-theme::header', 'Vendor header');
    $registry->registerFooter('vendor-theme::footer', 'Vendor footer');

    $schema = Schema::make(Livewire::make())->components([
        HeaderFieldset::make('header_options'),
        FooterFieldset::make('footer_options'),
    ]);

    $flatten = function (array $components) use (&$flatten): array {
        return collect($components)->flatMap(function (Component $component) use (&$flatten): array {
            $children = array_filter(
                $component->getChildSchema()?->getComponents() ?? [],
                fn (mixed $child): bool => $child instanceof Component,
            );

            return [$component, ...$flatten(array_values($children))];
        })->all();
    };
    $components = collect($flatten($schema->getComponents()));
    $headerFile = $components->first(fn (Component $component): bool => method_exists($component, 'getName') && $component->getName() === 'header_file');
    $footerFile = $components->first(fn (Component $component): bool => method_exists($component, 'getName') && $component->getName() === 'footer_file');

    expect($headerFile)->toBeInstanceOf(Select::class)
        ->and($headerFile->getOptions())->toBe($registry->headerOptions())
        ->and($footerFile)->toBeInstanceOf(Select::class)
        ->and($footerFile->getOptions())->toBe($registry->footerOptions());
});
