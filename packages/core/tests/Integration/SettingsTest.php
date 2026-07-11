<?php

declare(strict_types=1);

use function Pest\Laravel\assertDatabaseHas;

it('has proper settings stored in the database', function (): void {
    assertDatabaseHas('settings', [
        'group' => 'core',
    ]);
});
