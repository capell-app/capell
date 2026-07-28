#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/release/ReleaseEngine.php';
require __DIR__ . '/release/ReleaseEligibility.php';

use Capell\Release\ProcessCommandRunner;
use Capell\Release\ReleaseEligibilityChecker;
use Capell\Release\ReleaseException;
use Symfony\Component\Process\Process;

$sha = $argv[1] ?? null;

if (! is_string($sha)) {
    fwrite(STDERR, "An exact Core source SHA is required.\n");
    exit(1);
}

try {
    $useLocalGates = getenv('CAPELL_RELEASE_LOCAL_GATES') === '1';
    $sealedEvidencePath = getenv('CAPELL_RELEASE_LOCAL_EVIDENCE');
    $sealedExpectedShas = null;
    $localGateRunner = null;

    if (is_string($sealedEvidencePath) && $sealedEvidencePath !== '') {
        if (! str_starts_with($sealedEvidencePath, '/') || ! is_file($sealedEvidencePath)) {
            throw new ReleaseException('CAPELL_RELEASE_LOCAL_EVIDENCE must name an absolute sealed evidence file.');
        }

        $sealedEvidence = file_get_contents($sealedEvidencePath);
        if (! is_string($sealedEvidence)) {
            throw new ReleaseException('Release paused: sealed local release eligibility evidence is unreadable.');
        }

        $decodedEvidence = json_decode($sealedEvidence, true);
        $sealedExpectedShas = is_array($decodedEvidence)
            ? [
                'app_preflight' => $decodedEvidence['gates']['app_preflight']['sha'] ?? null,
                'packages_preflight' => $decodedEvidence['gates']['packages_preflight']['sha'] ?? null,
            ]
            : null;

        $localGateRunner = static fn (array $expectedShas): string => $sealedEvidence;
    } elseif ($useLocalGates) {
        $coreRoot = getenv('CAPELL_RELEASE_CORE_ROOT');
        $appRoot = getenv('CAPELL_RELEASE_APP_ROOT');
        $packagesRoot = getenv('CAPELL_RELEASE_PACKAGES_ROOT');

        if (
            ! is_string($coreRoot) || $coreRoot === ''
            || ! is_string($appRoot) || $appRoot === ''
            || ! is_string($packagesRoot) || $packagesRoot === ''
        ) {
            throw new ReleaseException(
                'CAPELL_RELEASE_CORE_ROOT, CAPELL_RELEASE_APP_ROOT, and CAPELL_RELEASE_PACKAGES_ROOT are required for local release gates.',
            );
        }

        $localGateRunner = static function (array $expectedShas) use ($coreRoot, $appRoot, $packagesRoot): string {
            $artifactsRoot = getenv('CAPELL_RELEASE_LOCAL_ARTIFACTS');
            $artifacts = is_string($artifactsRoot) && $artifactsRoot !== ''
                ? $artifactsRoot
                : $appRoot . '/.release-state/local-gates-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
            $process = new Process([
                PHP_BINARY,
                $appRoot . '/scripts/release-local-gates.php',
                '--core-root=' . $coreRoot,
                '--core-sha=' . $expectedShas['core_test_all'],
                '--app-root=' . $appRoot,
                '--app-sha=' . $expectedShas['app_preflight'],
                '--packages-root=' . $packagesRoot,
                '--packages-sha=' . $expectedShas['packages_preflight'],
                '--artifacts=' . $artifacts,
            ]);
            $process->setTimeout(null);
            $process->run(static function (string $type, string $buffer): void {
                if ($type === Process::ERR) {
                    fwrite(STDERR, $buffer);
                }
            });

            if (! $process->isSuccessful()) {
                throw new ReleaseException('Release paused: repository-owned local gates failed.');
            }

            return $process->getOutput();
        };
    }

    $evidence = new ReleaseEligibilityChecker(
        new ProcessCommandRunner,
        $localGateRunner,
    )->check(
        $sha,
        is_string($sealedExpectedShas['app_preflight'] ?? null) ? $sealedExpectedShas['app_preflight'] : null,
        is_string($sealedExpectedShas['packages_preflight'] ?? null) ? $sealedExpectedShas['packages_preflight'] : null,
    );
} catch (ReleaseException $releaseException) {
    fwrite(STDERR, $releaseException->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(
    STDOUT,
    json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
);
