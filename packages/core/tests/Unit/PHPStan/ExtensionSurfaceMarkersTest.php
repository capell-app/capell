<?php

declare(strict_types=1);

it('keeps class markers aligned with the extension surface catalogue', function (): void {
    $path = dirname(__DIR__, 5) . '/docs/packages/extension-surface-catalog.json';
    $catalogue = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $checked = [];

    foreach ($catalogue['surfaces'] as $surface) {
        $name = $surface['identifier'];

        if (! str_starts_with((string) $name, 'Capell\\')) {
            continue;
        }

        $reflection = new ReflectionClass($name);
        $comment = $reflection->getDocComment() ?: '';
        $marker = match ($surface['stability']) {
            'stable' => '@api',
            'experimental' => '@experimental',
            'internal' => '@internal',
            default => throw new UnexpectedValueException('Unknown extension surface stability.'),
        };
        $opposite = array_diff(['@api', '@internal', '@experimental'], [$marker]);

        expect($comment)->toContain($marker)->not->toContain(...$opposite);
        $checked[$name] = true;
    }

    expect($checked)->not->toBeEmpty();
});
