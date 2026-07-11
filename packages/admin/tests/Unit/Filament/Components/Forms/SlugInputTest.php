<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\SlugInput;

it('trims the display base url for the root slug only', function (): void {
    $component = SlugInput::make('slug')
        ->slugInputBaseUrl('http://capell.test/')
        ->slugInputBasePath('/');

    expect($component->getFullBaseUrl())->toBe('http://capell.test/')
        ->and($component->getDisplayBaseUrl('/'))->toBe('http://capell.test')
        ->and($component->getDisplayBaseUrl('welcome'))->toBe('http://capell.test/');
});
