<?php

declare(strict_types=1);

use Capell\Core\Actions\Reporting\ProcessReportingIncidentsAction;
use Capell\Core\Contracts\Reporting\Reporter;
use Capell\Core\Data\Reporting\RedactedSignalData;
use Capell\Core\Enums\Reporting\IncidentStatus;
use Capell\Core\Models\ReportingIncident;
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

it('rejects unredacted stored incidents before retry delivery and advances the scheduler cursor', function (): void {
    $incident = ReportingIncident::query()->create([
        'fingerprint' => hash('sha256', 'unsafe-stored-incident'),
        'signal' => [
            'name' => 'runtime.failed', 'category' => 'runtime', 'severity' => 'error',
            'message' => 'Failed.', 'operator_summary' => 'Inspect.', 'correlation_id' => 'fixture',
            'run_id' => null, 'context' => ['password' => 'stored-secret'],
        ],
        'status' => IncidentStatus::Open, 'health' => false, 'deliveries' => [], 'opened_at' => now(),
    ]);
    expect(ProcessReportingIncidentsAction::run())->toBe(['failed' => 1]);
    $incident->refresh();
    expect($incident->checked_at)->not->toBeNull()
        ->and($incident->deliveries)->toBe([]);
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
