<?php

declare(strict_types=1);

it('keeps fallback validation output readable in light and dark mode', function (): void {
    $view = file_get_contents(
        dirname(__DIR__, 3) . '/resources/views/components/exchanger/import-session-validation.blade.php',
    );

    expect($view)
        ->toContain('text-gray-900')
        ->toContain('dark:text-gray-100');
});
