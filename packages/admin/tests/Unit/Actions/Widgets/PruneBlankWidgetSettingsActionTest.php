<?php

declare(strict_types=1);

use Capell\Admin\Actions\Widgets\PruneBlankWidgetSettingsAction;

it('recursively removes blank settings while preserving meaningful falsey values', function (): void {
    $settings = PruneBlankWidgetSettingsAction::run([
        'empty_string' => '',
        'whitespace' => '   ',
        'null' => null,
        'empty_array' => [],
        'zero' => 0,
        'false' => false,
        'nested' => [
            'blank' => '',
            'value' => 'kept',
        ],
        'list' => ['', 'kept'],
    ]);

    expect($settings)->toBe([
        'zero' => 0,
        'false' => false,
        'nested' => ['value' => 'kept'],
        'list' => [1 => 'kept'],
    ]);
});
