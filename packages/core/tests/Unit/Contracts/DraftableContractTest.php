<?php

declare(strict_types=1);

use Capell\Core\Contracts\DraftableContract;
use Capell\Core\Models\Page;

it('Page implements DraftableContract', function (): void {
    $interfaces = class_implements(Page::class);

    expect(in_array(DraftableContract::class, $interfaces, true))->toBeTrue();
});

it('getDraftKey method exists on Page', function (): void {
    expect(method_exists(Page::class, 'getDraftKey'))->toBeTrue();
});
