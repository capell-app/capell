<?php

declare(strict_types=1);

use Capell\Tests\Support\StylesheetRuntime;

it('provides reduced motion, plain preflight panels and branded step surfaces', function (): void {
    $path = dirname(__DIR__, 2) . '/resources/css/installer.css';
    $styles = file_get_contents($path);

    // Reduced-motion support is an accessibility contract in the delivered stylesheet.
    expect($styles)->toContain('prefers-reduced-motion');

    $result = StylesheetRuntime::inspect($path);
    $rules = array_filter($result['rules'], static fn (array $rule): bool => $rule['classes'] === ['preflight-panel']);
    expect($rules)->not->toBeEmpty();
    foreach ($rules as $rule) {
        $backgrounds = array_filter($rule['declarations'], static fn (array $declaration): bool => str_starts_with($declaration['property'], 'background'));
        foreach ($backgrounds as $background) {
            expect($background['gradients'])->toBe([]);
        }
    }

    $panelRules = array_filter($result['rules'], static fn (array $rule): bool => $rule['classes'] === ['installer-step-panel']);
    expect($panelRules)->not->toBeEmpty();
    $variables = [];
    foreach ($panelRules as $rule) {
        foreach ($rule['declarations'] as $declaration) {
            if ($declaration['property'] === 'background') {
                $variables = [...$variables, ...$declaration['variables']];
            }
        }
    }

    expect($variables)->toContain('--brand-panel');
});
