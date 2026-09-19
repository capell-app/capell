<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Frontend\Support\Logging\FrontendLogger;
use Capell\Frontend\Support\Render\PublicViewQueryGuard;
use Saade\FilamentAdjacencyList\Forms\Components\Concerns\HasRelationship;

arch()->preset()->php()->ignoring([
    'var_export',
    FrontendLogger::class,
    PublicViewQueryGuard::class,
    // Walking the call stack is the only way to attribute a contribution to the provider currently booting.
    ExtensionContributionReceiptRegistry::class,
]);

arch()->preset()->laravel();

arch()->preset()->security()->ignoring([
    HasRelationship::class,
]);

it('does not allow debug functions or forbidden functions')
    ->expect(['dd', 'dump', 'print_r', 'die', 'ray', 'rd', 'var_dump', 'exit', 'env', 'sleep', 'usleep'])
    ->toBeUsedInNothing()
    ->ignoring([
        EditPage::class,
    ]);

arch()
    ->expect([
        'Capell\Core',
    ])
    ->classes()
    ->toUseStrictEquality()
    /*->toHavePropertiesDocumented()
    ->toHaveMethodsDocumented()*/;
