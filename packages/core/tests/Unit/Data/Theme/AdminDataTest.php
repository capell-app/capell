<?php

declare(strict_types=1);

use Capell\Core\Data\Theme\AdminData;

it('maps admin theme data back to legacy meta without null values', function (): void {
    expect(new AdminData(
        icon: 'heroicon-o-home',
        image: 'admin.png',
        description: 'A polished editorial theme.',
    )->toLegacyMeta())->toBe([
        'icon' => 'heroicon-o-home',
        'image' => 'admin.png',
        'description' => 'A polished editorial theme.',
    ]);

    expect(new AdminData(icon: 'heroicon-o-home')->toLegacyMeta())->toBe([
        'icon' => 'heroicon-o-home',
    ]);
});
