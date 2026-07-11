<?php

declare(strict_types=1);

use Capell\Core\Actions\UpdateTailwindClassesFileAction;
use Illuminate\Support\Facades\File;

it('updates tailwind safelist file', function (): void {
    File::spy();

    UpdateTailwindClassesFileAction::run(['main-class']);

    $expectedPath = rtrim(storage_path('capell'), '/') . '/tailwind-classes.txt';
    File::shouldHaveReceived('put')
        ->with($expectedPath, 'main-class')
        ->once();
});
