<?php

declare(strict_types=1);

/*
 * Pest's `expect($callable)->not->toThrow(Throwable::class)` passes even when the callable throws,
 * because the negation also swallows the expectation's own failure. A test or generated stub
 * written that way can never go red. Call the code directly instead; an exception fails the test.
 */

/** @return list<string> */
function neverFailingThrowAssertionOffenders(string $root, array $paths): array
{
    $offenders = [];

    foreach ($paths as $path) {
        $contents = file_get_contents($root . '/' . $path);
        throw_if($contents === false, RuntimeException::class, 'Cannot read test file: ' . $path);

        if (preg_match('/->\s*not\s*->\s*toThrow\(\s*\\\\?Throwable::class\s*\)/', $contents) === 1) {
            $offenders[] = $path;
        }
    }

    return $offenders;
}

it('keeps never-failing not->toThrow(Throwable::class) assertions out of tests and stubs', function (): void {
    $root = dirname(__DIR__, 2);
    $output = [];
    exec('git -C ' . escapeshellarg($root) . ' ls-files -- "*.php" "*.php.stub"', $output, $exitCode);

    expect($exitCode)->toBe(0);

    $paths = array_values(array_filter(
        $output,
        static fn (string $path): bool => preg_match('~(^|/)(tests|stubs)/~', $path) === 1
            && $path !== 'tests/Unit/NeverFailingThrowAssertionTest.php',
    ));

    expect($paths)->not->toBeEmpty()
        ->and(neverFailingThrowAssertionOffenders($root, $paths))->toBe([]);
});

it('flags the never-failing shape however it is spaced or qualified', function (string $source): void {
    $root = sys_get_temp_dir() . '/capell-throw-guard-' . bin2hex(random_bytes(8));
    mkdir($root);

    try {
        file_put_contents($root . '/ExampleTest.php', $source);

        expect(neverFailingThrowAssertionOffenders($root, ['ExampleTest.php']))->toBe(['ExampleTest.php']);
    } finally {
        unlink($root . '/ExampleTest.php');
        rmdir($root);
    }
})->with([
    'inline' => '<?php expect(fn () => run())->not->toThrow(Throwable::class);',
    'qualified' => '<?php expect(fn () => run())->not->toThrow(' . chr(92) . 'Throwable::class);',
    'chained over lines' => "<?php expect(fn () => run())\n    ->not\n    ->toThrow( Throwable::class );",
]);

it('leaves specific not->toThrow assertions alone, because those do fail', function (): void {
    $root = sys_get_temp_dir() . '/capell-throw-guard-' . bin2hex(random_bytes(8));
    mkdir($root);

    try {
        file_put_contents($root . '/ExampleTest.php', '<?php expect(fn () => run())->not->toThrow(RuntimeException::class);');

        expect(neverFailingThrowAssertionOffenders($root, ['ExampleTest.php']))->toBe([]);
    } finally {
        unlink($root . '/ExampleTest.php');
        rmdir($root);
    }
});
