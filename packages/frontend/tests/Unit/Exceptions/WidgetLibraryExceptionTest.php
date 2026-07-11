<?php

declare(strict_types=1);

use Capell\Frontend\Exceptions\WidgetLibraryException;

it('exposes widget context for error renderers', function (): void {
    $exception = new WidgetLibraryException(
        message: 'Failed to render widgets.',
        widgets: [['type' => 'hero']],
        code: 500,
        previous: new RuntimeException('inner'),
    );

    expect($exception->getMessage())->toBe('Failed to render widgets.')
        ->and($exception->getCode())->toBe(500)
        ->and($exception->getPrevious())->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getContext())->toBe(['widgets' => [['type' => 'hero']]]);
});
