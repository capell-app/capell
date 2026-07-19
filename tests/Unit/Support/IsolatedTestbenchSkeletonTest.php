<?php

declare(strict_types=1);

use Capell\Tests\Support\IsolatedTestbenchSkeleton;

it('uses the run-unique Paratest token and falls back to the worker token', function (): void {
    $originalUniqueToken = getenv('UNIQUE_TEST_TOKEN');
    $originalTestToken = getenv('TEST_TOKEN');
    $token = new ReflectionMethod(IsolatedTestbenchSkeleton::class, 'token');

    try {
        putenv('TEST_TOKEN=11');
        putenv('UNIQUE_TEST_TOKEN=11_run-unique');

        expect($token->invoke(null))->toBe('11_run-unique');

        putenv('UNIQUE_TEST_TOKEN');

        expect($token->invoke(null))->toBe('11');
    } finally {
        putenv($originalUniqueToken === false ? 'UNIQUE_TEST_TOKEN' : 'UNIQUE_TEST_TOKEN=' . $originalUniqueToken);
        putenv($originalTestToken === false ? 'TEST_TOKEN' : 'TEST_TOKEN=' . $originalTestToken);
    }
});
