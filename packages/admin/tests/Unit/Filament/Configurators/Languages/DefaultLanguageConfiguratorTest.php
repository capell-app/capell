<?php

declare(strict_types=1);

use Capell\Admin\Filament\Configurators\Languages\DefaultLanguageConfigurator;
use Capell\Admin\Filament\Resources\Languages\Pages\ManageLanguages;
use Capell\Core\Models\Language;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

it('uses a plain flag text input by default', function (): void {
    $schema = Schema::make(new ManageLanguages)->model(Language::class)->operation('create');
    $schema->components(new DefaultLanguageConfigurator()->make($schema));

    $field = $schema->getComponent('flag');

    expect($field)->toBeInstanceOf(TextInput::class);
});
