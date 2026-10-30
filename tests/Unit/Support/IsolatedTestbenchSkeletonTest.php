<?php

declare(strict_types=1);

use Capell\Tests\Support\IsolatedTestbenchSkeleton;

it('keeps generated skeletons outside test discovery in the repository runtime directory', function (): void {
    $pathForToken = new ReflectionMethod(IsolatedTestbenchSkeleton::class, 'pathForToken');
    $repositoryPath = dirname(__DIR__, 3);

    $previous = getenv('CAPELL_TESTBENCH_SKELETON_ROOT');
    try {
        putenv('CAPELL_TESTBENCH_SKELETON_ROOT');
        expect($pathForToken->invoke(null, '11_run-unique'))
            ->toBe($repositoryPath . '/var/testbench-skeletons/11_run-unique');
    } finally {
        putenv($previous === false ? 'CAPELL_TESTBENCH_SKELETON_ROOT' : 'CAPELL_TESTBENCH_SKELETON_ROOT=' . $previous);
    }
});

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

it('can place an isolated skeleton in an explicitly owned scratch root', function (): void {
    $previous = getenv('CAPELL_TESTBENCH_SKELETON_ROOT');
    $pathForToken = new ReflectionMethod(IsolatedTestbenchSkeleton::class, 'pathForToken');
    try {
        putenv('CAPELL_TESTBENCH_SKELETON_ROOT=/owned/scratch');
        expect($pathForToken->invoke(null, 'worker'))->toBe('/owned/scratch/worker');
    } finally {
        putenv($previous === false ? 'CAPELL_TESTBENCH_SKELETON_ROOT' : 'CAPELL_TESTBENCH_SKELETON_ROOT=' . $previous);
    }
});
