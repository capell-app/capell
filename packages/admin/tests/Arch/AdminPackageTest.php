<?php

declare(strict_types=1);

use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Filament\Components\Forms\Interactions\InteractionSettingsSchema;
use Capell\Frontend\Filament\Settings\FrontendSettingsSchema;
use Illuminate\Console\Command;
use Spatie\LaravelData\Data;

it('Admin package should be standalone')
    ->expect('Capell\Admin')
    ->not()->toUse([
        'Capell\Frontend',
        'Capell\Installer',
        'Capell\Marketplace',
    ])
    ->ignoring([
        FrontendSettingsSchema::class,
        InteractionSettingsSchema::class,
    ]);

arch()
    ->expect('Capell\Admin\Filament\Configurators')
    ->toImplement(ConfiguratorInterface::class)
    ->toHaveSuffix('Configurator');

arch()
    ->expect('Capell\Admin\Contracts')
    ->toBeInterfaces();

arch()
    ->expect('Capell\Admin\Enums')
    ->toBeEnums()
    ->ignoring('Capell\Admin\Enums\Concerns');

arch()
    ->expect('Capell\Admin')
    ->not()->toBeEnums()
    ->ignoring(['Capell\Admin\Enums', 'Capell\Admin\Console\Commands', 'Capell\Admin\Exceptions']);

arch()
    ->expect('Capell\Admin')
    ->not()->toExtend(Command::class)
    ->ignoring(['Capell\Admin\Console\Commands']);

arch()
    ->expect('Capell\Admin')
    ->not()->toImplement(Throwable::class)
    ->ignoring(['Capell\Admin\Exceptions']);

arch()
    ->expect('Capell\Admin\Commands')
    ->classes()
    ->toHaveSuffix('Command')
    ->toExtend(Command::class)
    ->toHaveMethod('handle');

arch()
    ->expect('Capell\Admin\Data')
    ->classes()
    ->toExtend(Data::class)
    ->toHaveConstructor();

arch()
    ->expect('Capell\Admin\Exceptions')
    ->classes()
    ->toImplement('Throwable');

arch('actions')
    ->expect('Capell\Admin\Actions')
    ->toHaveMethod('handle')
    ->ignoring('Capell\Admin\Actions\Publishing\Concerns');
