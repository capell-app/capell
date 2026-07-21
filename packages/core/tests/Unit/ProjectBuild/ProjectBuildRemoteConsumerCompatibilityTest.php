<?php

declare(strict_types=1);

it('keeps the 1.0.14 project build consumer API unchanged while adding the 1.0.15 install API', function (): void {
    $root = dirname(__DIR__, 5);
    $fixture = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/project-build/consumer-api-compatibility.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $baseline = json_decode(
        (string) file_get_contents($root . '/docs/packages/stable-extension-api-baseline.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $surfaces = $baseline['surfaces'];

    expect($fixture['oldConsumer']['release'])->toBe('1.0.14')
        ->and($fixture['newConsumer']['release'])->toBe('1.0.15')
        ->and(array_intersect(
            array_keys($fixture['oldConsumer']['surfaces']),
            array_keys($fixture['newConsumer']['surfaces']),
        ))->toBe([]);

    foreach ([...$fixture['oldConsumer']['surfaces'], ...$fixture['newConsumer']['surfaces']] as $id => $identifier) {
        expect($surfaces)->toHaveKey($id)
            ->and($surfaces[$id]['identifier'])->toBe($identifier)
            ->and($surfaces[$id]['contractTestId'])->not->toBeNull();
    }

    foreach ($fixture['oldConsumer']['signatures'] as $id => $signature) {
        expect($surfaces[$id]['signature'])->toBe($signature);
    }
});
