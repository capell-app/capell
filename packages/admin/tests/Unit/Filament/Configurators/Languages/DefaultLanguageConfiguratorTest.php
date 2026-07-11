<?php

declare(strict_types=1);

use Capell\Admin\Filament\Configurators\Languages\DefaultLanguageConfigurator;
use Filament\Forms\Components\TextInput;

it('uses a plain flag text input by default', function (): void {
    $reflectionMethod = new ReflectionMethod(DefaultLanguageConfigurator::class, 'makeFlagField');

    $field = $reflectionMethod->invoke(new DefaultLanguageConfigurator);

    expect($field)->toBeInstanceOf(TextInput::class);
});
