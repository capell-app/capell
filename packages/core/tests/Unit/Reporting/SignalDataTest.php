<?php

declare(strict_types=1);

use Capell\Core\Data\Reporting\SignalData;
use Capell\Core\Enums\Reporting\FailureCategory;
use Capell\Core\Enums\Reporting\Severity;

it('redacts secrets and personal context before exposing a signal', function (): void {
    $signal = new SignalData(
        name: 'import.failed',
        category: FailureCategory::Dependency,
        severity: Severity::Error,
        message: 'Failed for person@example.com with Bearer private-token',
        operatorSummary: 'Inspect password="two word secret" at https://admin:secret@example.test/path?token=hidden',
        correlationId: 'correlation-1',
        runId: 'run-1',
        context: [
            'attempt' => 2,
            'retryable' => true,
            'nested' => [
                'apiKey' => 'secret-key',
                'email' => 'person@example.com',
                'phone' => '+44 7700 900123',
                'first_name' => 'Alice',
                'ip_address' => '192.0.2.10',
                'items' => [['access_token' => 'nested-secret']],
            ],
            'detail' => 'person@example.com token=hidden 192.0.2.10 +44 7700 900123',
            'object' => new class implements Stringable
            {
                public function __toString(): string
                {
                    throw new RuntimeException('Objects must never be inspected.');
                }
            },
        ],
    );

    expect($signal->context['attempt'])->toBe(2)
        ->and($signal->context['retryable'])->toBeTrue()
        ->and($signal->context['nested']['apiKey'])->toBe('[redacted]')
        ->and($signal->context['nested']['items'][0]['access_token'])->toBe('[redacted]')
        ->and($signal->context['object'])->toBe('[redacted]');

    foreach ([$signal->message, $signal->operatorSummary, $signal->toJson(), $signal->toHuman(), json_encode($signal, JSON_THROW_ON_ERROR)] as $output) {
        // Each forbidden value needs its own assertion: one absence cannot prove all absences.
        foreach (['person@example.com', 'private-token', 'two word secret', 'admin:secret', 'hidden', 'secret-key', 'Alice', '192.0.2.10', '+44 7700 900123', 'nested-secret'] as $sensitive) {
            expect($output)->not->toContain($sensitive);
        }
    }
});

it('provides stable human and JSON representations with correlation and run identifiers', function (): void {
    $signal = new SignalData('queue.failed', FailureCategory::Runtime, Severity::Critical, "Worker failed.\n", 'Restart the worker.', 'trace-1', 'run-2', ['attempt' => 3]);

    expect(json_decode($signal->toJson(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'name' => 'queue.failed',
        'category' => 'runtime',
        'severity' => 'critical',
        'message' => 'Worker failed.',
        'operator_summary' => 'Restart the worker.',
        'correlation_id' => 'trace-1',
        'run_id' => 'run-2',
        'context' => ['attempt' => 3],
    ])->and($signal->toHuman())->toContain('[CRITICAL]', 'queue.failed', 'runtime', 'Worker failed.', 'Restart the worker.', 'trace-1', 'run-2');
});

it('bounds recursive context and produces valid JSON for unsupported values', function (): void {
    $context = ['invalid' => "bad\xB1", 'number' => INF];
    $context['cycle'] = &$context;

    $signal = new SignalData('runtime.failed', FailureCategory::Runtime, Severity::Error, 'Failure', 'Inspect the service.', 'trace-1', context: $context);

    expect(strlen($signal->toJson()))->toBeLessThan(10000)
        ->and(json_decode($signal->toJson(), true, flags: JSON_THROW_ON_ERROR)['context']['number'])->toBe('[redacted]');
});

it('rejects invalid signal identities rather than allowing unbounded policy keys or personal identifiers', function (string $name, string $correlationId): void {
    expect(fn (): SignalData => new SignalData($name, FailureCategory::Runtime, Severity::Error, 'Failure', 'Inspect.', $correlationId))
        ->toThrow(InvalidArgumentException::class);
})->with([
    ['', 'trace-1'],
    ['arbitrary signal text', 'trace-1'],
    ['runtime.failed', 'person@example.com'],
    ['runtime.failed', ''],
]);

it('keeps failure category and severity wire values stable', function (): void {
    expect(array_column(FailureCategory::cases(), 'value'))->toBe([
        'configuration', 'validation', 'authentication', 'authorization', 'dependency', 'timeout', 'rate_limit', 'persistence', 'capacity', 'runtime',
    ])->and(array_column(Severity::cases(), 'value'))->toBe([
        'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency',
    ]);
});

it('redacts quoted assignments and personal names as well as sensitive map keys', function (): void {
    $signal = new SignalData(
        'runtime.failed',
        FailureCategory::Runtime,
        Severity::Error,
        'Failed: {"client_secret":"secret with spaces", "password":"another secret"}',
        'Inspect.',
        'trace-1',
        context: ['name' => 'Alice Smith', 'user' => 'Alice', 'person@example.com' => 'diagnostic'],
    );

    foreach (['secret with spaces', 'another secret', 'Alice', 'person@example.com'] as $sensitive) {
        expect($signal->toJson())->not->toContain($sensitive);
    }
});

it('redacts complete escaped assignments and cookie headers in every representation', function (string $text, string $secret): void {
    $signal = new SignalData('runtime.failed', FailureCategory::Runtime, Severity::Error, $text, $text, 'trace-1', context: ['nested' => ['detail' => $text]]);

    foreach ([$signal->message, $signal->operatorSummary, $signal->context['nested']['detail'], $signal->toJson(), $signal->toHuman(), json_encode($signal, JSON_THROW_ON_ERROR)] as $output) {
        expect($output)->not->toContain($secret);
    }
})->with([
    'escaped double quote' => ['{"client_secret":"before\\"LEAK_AFTER_QUOTE tail"}', 'LEAK_AFTER_QUOTE'],
    'escaped single quote' => ["password='before\\'LEAK_AFTER_QUOTE tail'", 'LEAK_AFTER_QUOTE'],
    'escaped backslash and quote' => ['token="before\\\\\\"LEAK_AFTER_QUOTE tail"', 'LEAK_AFTER_QUOTE'],
    'unterminated double quote' => ['password="before LEAK_UNTERMINATED tail', 'LEAK_UNTERMINATED'],
    'unterminated single quote' => ["secret='before LEAK_UNTERMINATED tail", 'LEAK_UNTERMINATED'],
    'cookie header' => ['Cookie: harmless=1; sessionid=SESSION_SECRET_REVIEW', 'SESSION_SECRET_REVIEW'],
    'unlabelled cookie' => ['Cookie: harmless=1; remember=UNLABELLED_COOKIE_SECRET; theme=dark', 'UNLABELLED_COOKIE_SECRET'],
    'set-cookie header' => ['Set-Cookie: sessionid=SESSION_SECRET_REVIEW; Path=/; HttpOnly', 'SESSION_SECRET_REVIEW'],
    'quoted cookie header' => ['{"Cookie":"harmless=1; sid=SESSION_SECRET_REVIEW"}', 'SESSION_SECRET_REVIEW'],
    'session assignment' => ['sessionid=SESSION_SECRET_REVIEW', 'SESSION_SECRET_REVIEW'],
]);

it('preserves text following the real end of a quoted assignment or header', function (): void {
    $signal = new SignalData('runtime.failed', FailureCategory::Runtime, Severity::Error, 'secret="hidden\\\\" retryable=true', "Cookie: sid=hidden; theme=dark\nRetry the operation.", 'trace-1');

    expect($signal->message)->toContain('retryable=true')->not->toContain('hidden')
        ->and($signal->operatorSummary)->toContain('Retry the operation.')->not->toContain('hidden');
});
