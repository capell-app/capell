<?php

declare(strict_types=1);

use Capell\Tests\Support\WorkerCompiledViewPath;
use Illuminate\Support\Env;

// An isolated test process starts with its parent worker's environment copied into
// $_SERVER and $_ENV, which Laravel reads before getenv(). The child must still
// compile views into its own directory, or it overwrites the parent's compiled views.
it('makes Laravel read the worker compiled view path over one inherited from a parent process', function (): void {
    $variable = WorkerCompiledViewPath::VARIABLE;
    $previous = [
        'getenv' => getenv($variable),
        'server' => $_SERVER[$variable] ?? null,
        'env' => $_ENV[$variable] ?? null,
    ];

    $_SERVER[$variable] = 'storage/framework/views/phpunit-parent-worker';
    $_ENV[$variable] = 'storage/framework/views/phpunit-parent-worker';

    try {
        WorkerCompiledViewPath::apply('storage/framework/views/phpunit-child-process');

        expect(Env::get($variable))->toBe('storage/framework/views/phpunit-child-process')
            ->and(getenv($variable))->toBe('storage/framework/views/phpunit-child-process');
    } finally {
        putenv($previous['getenv'] === false ? $variable : $variable . '=' . $previous['getenv']);

        if ($previous['server'] === null) {
            unset($_SERVER[$variable]);
        } else {
            $_SERVER[$variable] = $previous['server'];
        }

        if ($previous['env'] === null) {
            unset($_ENV[$variable]);
        } else {
            $_ENV[$variable] = $previous['env'];
        }
    }
});
