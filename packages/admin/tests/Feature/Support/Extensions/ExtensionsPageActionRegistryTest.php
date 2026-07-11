<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Support\Extensions\ExtensionsPageActionRegistry;
use Filament\Actions\Action;

it('deduplicates keyed extension page header actions', function (): void {
    $registry = new ExtensionsPageActionRegistry;
    $page = Mockery::mock(ExtensionsPage::class);

    $registry->registerHeaderAction(Action::make('browseMarketplace'), 'capell-marketplace.browse');
    $registry->registerHeaderAction(Action::make('browseMarketplace'), 'capell-marketplace.browse');

    expect($registry->headerActions($page))->toHaveCount(1);
});
