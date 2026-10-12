<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Redirects\RedirectResource;

it('gives redirect navigation the same label as its destination', function (): void {
    expect(RedirectResource::getNavigationLabel())->toBe(RedirectResource::getTitleCasePluralModelLabel());
});
