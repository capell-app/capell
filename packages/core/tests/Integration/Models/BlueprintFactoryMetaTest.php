<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;

it('expands dot-notated meta string', function (): void {
    $type = Blueprint::factory()->meta('admin.type', 123)->make();
    expect($type->meta)->toBe([
        'admin' => [
            'type' => 123,
        ],
    ]);
});

it('expands dot-notated meta array', function (): void {
    $type = Blueprint::factory()->meta(['admin.type' => 123])->make();
    expect($type->meta)->toBe([
        'admin' => [
            'type' => 123,
        ],
    ]);
});

it('merges with existing meta', function (): void {
    $type = Blueprint::factory()
        ->meta(['foo' => 'bar'])
        ->meta('admin.type', 123)
        ->make();
    expect($type->meta)->toBe([
        'foo' => 'bar',
        'admin' => [
            'type' => 123,
        ],
    ]);
});
