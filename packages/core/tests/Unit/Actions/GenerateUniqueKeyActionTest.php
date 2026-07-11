<?php

declare(strict_types=1);

use Capell\Core\Actions\GenerateUniqueKeyAction;
use Capell\Core\Models\Theme;

it('generates a unique key for a model', function (): void {
    $theme = Theme::factory()->make(['name' => 'T']);

    $key = GenerateUniqueKeyAction::run($theme);

    expect($key)->toBeString()->not()->toBe('');
});
