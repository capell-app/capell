<?php

declare(strict_types=1);

use Capell\Core\Models\Translation;

it('keeps lossy float collisions dirty in raw metadata', function (string $stored, string $submitted): void {
    // Native JSON columns store these numbers as doubles, losing the distinction
    // before read-back. Exercise the model's raw comparison without that loss.
    $translation = new Translation;
    $translation->setRawAttributes(['meta' => $stored], sync: true);

    expect($translation->originalIsEquivalent('meta'))->toBeTrue()
        ->and($translation->isDirty('meta'))->toBeFalse();

    $translation->setRawAttributes(['meta' => $submitted]);

    expect($translation->originalIsEquivalent('meta'))->toBeFalse()
        ->and($translation->isDirty('meta'))->toBeTrue()
        ->and($translation->getDirty()['meta'])->toBe($submitted)
        ->and($translation->getRawOriginal('meta'))->toBe($stored);
})->with([
    'rounding collision' => ['{"value":0.1}', '{"value":0.10000000000000001}'],
    'underflow' => ['{"value":0.0}', '{"value":1e-400}'],
]);
