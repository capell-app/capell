<?php

declare(strict_types=1);

use Capell\Core\Models\Layout;

it('allows dynamic fillable and casts extension', function (): void {
    Layout::addFillable(['foo']);
    Layout::addCasts(['foo' => 'array']);
    $layout = new Layout;
    expect($layout->getFillable())->toContain('foo')
        ->and($layout->getCasts())->toHaveKey('foo')
        ->and($layout->getCasts()['foo'])->toBe('array');
});
