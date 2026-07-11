<?php

declare(strict_types=1);

use Capell\Core\Support\Themes\ThemeChromeRegistry;

it('registers public theme chrome components for admin selection', function (): void {
    $registry = new ThemeChromeRegistry;

    $registry->registerHeader('vendor-theme::header', 'Vendor header');
    $registry->registerFooter('vendor-theme::footer', 'Vendor footer');

    expect($registry->headerOptions())->toBe(['vendor-theme::header' => 'Vendor header'])
        ->and($registry->footerOptions())->toBe(['vendor-theme::footer' => 'Vendor footer']);
});
