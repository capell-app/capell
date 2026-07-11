<?php

declare(strict_types=1);

use Capell\Core\Models\Layout;

it('normalises persisted default values to booleans', function (): void {
    $layout = Layout::factory()->createOne(['default' => true]);

    expect($layout->refresh()->isDefault())->toBeTrue();

    $layout->forceFill(['default' => 0])->save();

    expect($layout->refresh()->isDefault())->toBeFalse();
});
