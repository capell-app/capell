<?php

declare(strict_types=1);

use Capell\Core\Contracts\Reporting\Reporter;
use Capell\Core\Data\Reporting\RedactedSignalData;
use Capell\Core\Support\Reporting\OperatorEmailChannel;
use Capell\Core\Support\Reporting\OperatorSignalRouter;

it('allows delivery channels to receive only the immutable redacted payload', function (string $class, string $method): void {
    $parameter = new ReflectionMethod($class, $method)->getParameters()[0] ?? null;
    $type = $parameter?->getType();

    expect($type)->toBeInstanceOf(ReflectionNamedType::class);
    $namedType = $type instanceof ReflectionNamedType
        ? $type
        : throw new RuntimeException('Reporting delivery payload type is unavailable.');

    expect($namedType->getName())->toBe(RedactedSignalData::class);
})->with([
    'extension reporter' => [Reporter::class, 'report'],
    'operator router' => [OperatorSignalRouter::class, 'report'],
    'operator email' => [OperatorEmailChannel::class, 'report'],
]);

it('converts a raw signal to its redacted payload only at the dispatch entry point', function (): void {
    $reportingRoot = dirname(__DIR__, 2) . '/src';
    $references = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($reportingRoot));

    foreach ($files as $file) {
        if (! $file->isFile()) {
            continue;
        }

        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        if (! is_string($source)) {
            continue;
        }

        $count = substr_count($source, 'RedactedSignalData::fromSignal(');
        if ($count > 0) {
            $references[str_replace(dirname(__DIR__, 4) . '/', '', $file->getPathname())] = $count;
        }
    }

    expect($references)->toBe([
        'packages/core/src/Actions/Reporting/DispatchSignalAction.php' => 1,
    ])
        ->and(file_get_contents($reportingRoot . '/Actions/Reporting/ProcessReportingIncidentsAction.php'))
        ->toContain('RedactedSignalData::fromStoredArray($incident->signal)')
        ->not->toContain('SignalData::fromArray(');
});

it('keeps every logger resolution mechanism behind the reporting transport boundary', function (): void {
    $sourceRoot = dirname(__DIR__, 2) . '/src';
    $reportingRoots = [
        $sourceRoot . '/Actions/Reporting',
        $sourceRoot . '/Contracts/Reporting',
        $sourceRoot . '/Data/Reporting',
        $sourceRoot . '/Support/Reporting',
    ];
    $allowed = $sourceRoot . '/Support/Reporting/ReportingTransportBoundary.php';
    $violations = [];

    foreach ($reportingRoots as $reportingRoot) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($reportingRoot));

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            if ($file->getPathname() === $allowed) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            if (! is_string($source)) {
                continue;
            }

            $code = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= is_array($token) ? $token[1] : $token;
            }

            foreach ([
                'Log facade' => '/\\bLog\\s*::/',
                'logger helper' => '/\\blogger\\s*\\(/i',
                'global log resolution' => '/\\b(?:app|resolve)\\s*\\(\\s*([\'\"])log\\1/i',
                'container log resolution' => '/->\\s*make\\s*\\(\\s*([\'\"])log\\1/i',
                'PSR logger resolution' => '/\\bLoggerInterface\\b/',
                'Laravel log manager resolution' => '/\\bLogManager\\b/',
            ] as $description => $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    $violations[] = str_replace(dirname(__DIR__, 4) . '/', '', $file->getPathname()) . ': ' . $description;
                }
            }
        }
    }

    expect($violations)->toBe([]);
});
