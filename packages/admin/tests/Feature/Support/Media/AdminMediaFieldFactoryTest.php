<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Media\AdminMediaFieldFactory;
use Capell\Admin\Filament\Components\Forms\MediaLibraryFileUpload;
use Capell\Core\Contracts\Media\MediaFieldFactory;
use Filament\Forms\Components\Field;

it('resolves the Admin-owned field adapter for first-party schemas', function (): void {
    expect(resolve(AdminMediaFieldFactory::class)->make('image'))->toBeInstanceOf(Field::class)
        ->and(MediaLibraryFileUpload::make('image'))->toBeInstanceOf(Field::class);
});

it('keeps the Core Filament field contract resolvable as a 1.x adapter', function (): void {
    expect(resolve(MediaFieldFactory::class)->make('image'))->toBeInstanceOf(Field::class);
});
