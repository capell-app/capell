<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('keeps the default custom head hook non-opinionated', function (): void {
    $html = Blade::render(
        <<<'BLADE'
        <x-capell::app.head.custom
            title="Example"
            keywords="testing"
            description="Default head hook"
        />
        BLADE,
    );

    expect(trim($html))->toBe('')
        ->and($html)->not->toContain('localStorage.theme')
        ->and($html)->not->toContain('updateHeaderSticky')
        ->and($html)->not->toContain('--color-brand');
});
