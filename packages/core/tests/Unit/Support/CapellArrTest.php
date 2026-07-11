<?php

declare(strict_types=1);

use Capell\Core\Support\CapellArr;

it('returns arrays unchanged and safely defaults other values', function (): void {
    $value = ['key' => 'value', 2 => ['nested']];

    expect(CapellArr::array($value))->toBe($value)
        ->and(CapellArr::array(null))->toBe([])
        ->and(CapellArr::array('value'))->toBe([])
        ->and(CapellArr::array(42))->toBe([]);
});
