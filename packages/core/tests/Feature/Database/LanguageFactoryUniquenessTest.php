<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Faker\Factory as FakerFactory;
use Faker\Generator;

/**
 * Factories take the container's cached per-locale Generator, so seeding it would leak into every
 * later test in the worker. Each test gets a fresh Generator instead, and the original is put back.
 */
beforeEach(function (): void {
    $this->originalFaker = resolve(Generator::class);
});

afterEach(function (): void {
    app()->instance(Generator::class, $this->originalFaker);
});

it('creates two hundred languages without a code collision under any seed', function (int $seed): void {
    $generator = FakerFactory::create(config()->string('app.faker_locale', 'en_US'));
    $generator->seed($seed);

    app()->instance(Generator::class, $generator);

    Language::factory()->count(200)->create();

    expect(Language::query()->count())->toBe(200)
        ->and(Language::query()->distinct()->count('code'))->toBe(200);
})->with([1, 2, 3, 20261114]);

it('keeps drawing unique codes after the private-use range is used up', function (): void {
    Language::factory()->count(600)->create();

    expect(Language::query()->distinct()->count('code'))->toBe(600)
        ->and(Language::query()->whereRaw('length(code) > 12')->exists())->toBeFalse();
});

it('never builds a language with a generator of its own, which would defeat unique() and seeding', function (): void {
    $sources = glob(dirname(__DIR__, 3) . '/database/factories/*.php') ?: [];
    $offenders = array_values(array_filter(
        $sources,
        static fn (string $file): bool => str_contains((string) file_get_contents($file), FakerFactory::class . '::create'),
    ));

    expect($sources)->not->toBeEmpty()
        ->and(array_map(basename(...), $offenders))->toBe([]);
});
