<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Media\AdminMediaFieldFactory;
use Capell\Admin\Filament\Components\Forms\MediaLibraryFileUpload;
use Filament\Forms\Components\Field;

it('resolves the Admin-owned field adapter for first-party schemas', function (): void {
    expect(resolve(AdminMediaFieldFactory::class)->make('image'))->toBeInstanceOf(Field::class)
        ->and(MediaLibraryFileUpload::make('image'))->toBeInstanceOf(Field::class);
});
