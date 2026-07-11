<?php

declare(strict_types=1);

use Capell\Admin\Actions\GenerateComponentKeyAction;

it('generates a deterministic component key', function (): void {
    $key = GenerateComponentKeyAction::run('Title', 1);

    expect($key)->toBeString()->not()->toBe('');
});
