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

it('redacts encoded credentials and complete authentication headers in every representation', function (string $text): void {
    $signal = new SignalData('runtime.failed', FailureCategory::Runtime, Severity::Error, $text, $text, 'trace-1', context: ['nested' => ['detail' => $text]]);

    foreach ([$signal->message, $signal->operatorSummary, $signal->context['nested']['detail'], $signal->toJson(), $signal->toHuman(), json_encode($signal, JSON_THROW_ON_ERROR)] as $output) {
        expect($output)->not->toContain('ENCODED_CREDENTIAL_LEAK');
    }
})->with([
    'unicode key' => ['{"\\u0070assword":"ENCODED_CREDENTIAL_LEAK"}'],
    'unicode delimiter' => ['client_secret\\u003dENCODED_CREDENTIAL_LEAK'],
    'nested JSON string' => [json_encode(['payload' => '{"client_secret":"ENCODED_CREDENTIAL_LEAK"}'], JSON_THROW_ON_ERROR)],
    'nested unicode JSON string' => [json_encode(['payload' => '{"\\u0070assword":"ENCODED_CREDENTIAL_LEAK"}'], JSON_THROW_ON_ERROR)],
    'twice nested JSON string' => [json_encode(['payload' => json_encode(['payload' => '{"client_secret":"ENCODED_CREDENTIAL_LEAK"}'], JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR)],
    'nested assignment string' => [json_encode(['payload' => 'client_secret="before ENCODED_CREDENTIAL_LEAK"'], JSON_THROW_ON_ERROR)],
    'twice nested assignment string' => [json_encode(['payload' => json_encode(['payload' => 'client_secret="before ENCODED_CREDENTIAL_LEAK"'], JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR)],
    'encoded assignment' => ['client_secret%3DENCODED_CREDENTIAL_LEAK'],
    'encoded colon and quotes' => ['%22password%22%3A%22ENCODED_CREDENTIAL_LEAK%22'],
    'encoded key' => ['%70assword=ENCODED_CREDENTIAL_LEAK'],
    'double encoding' => ['client_secret%253DENCODED_CREDENTIAL_LEAK'],
    'encoded quoted value' => ['password=%22before ENCODED_CREDENTIAL_LEAK"'],
    'unicode quoted value' => ['password=\\u0022before ENCODED_CREDENTIAL_LEAK"'],
    'folded cookie' => ["Cookie: harmless=1;\r\n sid=ENCODED_CREDENTIAL_LEAK\r\nRetry the operation."],
    'folded set-cookie' => ["Set-Cookie: harmless=1;\n\tsid=ENCODED_CREDENTIAL_LEAK\nRetry the operation."],
    'digest authorization' => ['Authorization: Digest username="some-user", response="ENCODED_CREDENTIAL_LEAK"'],
    'folded authorization' => ["Authorization: Digest username=\"some-user\",\r\n response=\"ENCODED_CREDENTIAL_LEAK\""],
    'proxy authorization' => ['Proxy-Authorization: Digest username="some-user", response="ENCODED_CREDENTIAL_LEAK"'],
    'encoded folded header' => ['Cookie%3A%20harmless=1%3B%0D%0A%20sid=ENCODED_CREDENTIAL_LEAK'],
]);

it('recognises encoded sensitive context keys', function (string $key): void {
    $signal = new SignalData('runtime.failed', FailureCategory::Runtime, Severity::Error, 'Failed.', 'Inspect.', 'trace-1', context: ['nested' => [$key => 'ENCODED_CREDENTIAL_LEAK']]);

    expect($signal->toJson())->not->toContain('ENCODED_CREDENTIAL_LEAK');
})->with(['\\u0070assword', '%70assword', '%2570assword', '\\u0065mail']);

it('withholds form encoded and structured credentials in every signal representation', function (string $text): void {
    $signal = new SignalData('runtime.failed', FailureCategory::Runtime, Severity::Error, $text, $text, 'trace-1', context: ['detail' => $text]);

    foreach ([$signal->message, $signal->operatorSummary, $signal->context['detail'], $signal->toJson(), $signal->toHuman(), json_encode($signal, JSON_THROW_ON_ERROR)] as $output) {
        expect($output)->not->toContain('STRUCTURED_CREDENTIAL_LEAK');
    }
})->with([
    'form assignment' => ['password+%3D+STRUCTURED_CREDENTIAL_LEAK'],
    'double form assignment' => ['client_secret%2B%253D%2BSTRUCTURED_CREDENTIAL_LEAK'],
    'form spacing' => ['password+=+STRUCTURED_CREDENTIAL_LEAK'],
    'nested object' => ['{"password": { "value": "STRUCTURED_CREDENTIAL_LEAK" }}'],
    'nested array' => ['{"password": [ "STRUCTURED_CREDENTIAL_LEAK", { "value": "second" } ]}'],
    'prefixed object' => ['Failure: {"client_secret": { "value": "STRUCTURED_CREDENTIAL_LEAK" }}'],
    'truncated object' => ['{"password": { "value": "STRUCTURED_CREDENTIAL_LEAK"'],
    'nested JSON string' => [json_encode(['payload' => '{"password": { "value": "STRUCTURED_CREDENTIAL_LEAK" }}'], JSON_THROW_ON_ERROR)],
    'encoded object' => [urlencode('{"password": { "value": "STRUCTURED_CREDENTIAL_LEAK" }}')],
    'double encoded array' => [urlencode(urlencode('{"password": [ "STRUCTURED_CREDENTIAL_LEAK" ]}'))],
]);

it('preserves harmless encoded diagnostics and text after folded headers', function (): void {
    $signal = new SignalData('runtime.failed', FailureCategory::Runtime, Severity::Error, 'Progress: 50%25; {"status":"still \\"pending\\""}', "Cookie: harmless=1;\r\n sid=hidden\r\nRetry the operation.", 'trace-1');

    expect($signal->message)->toBe('Progress: 50%25; {"status":"still \\"pending\\""}')
        ->and($signal->operatorSummary)->toBe('[redacted] Retry the operation.');
});

it('fails closed when encoded text exceeds the decoding bound', function (): void {
    $text = 'client_secret=ENCODED_CREDENTIAL_LEAK';
    for ($layer = 0; $layer < 12; $layer++) {
        $text = rawurlencode($text);
    }

    $signal = new SignalData('runtime.failed', FailureCategory::Runtime, Severity::Error, $text, 'Inspect.', 'trace-1');

    expect($signal->message)->toBe('[redacted]');
});

it('still redacts filesystem paths beside harmless escaped quotes', function (string $path): void {
    $signal = new SignalData('runtime.failed', FailureCategory::Runtime, Severity::Error, 'Failed at "' . $path . '"', 'Inspect.', 'trace-1');

    expect($signal->message)->not->toContain('PRIVATE_DIRECTORY');
})->with(['/srv/PRIVATE_DIRECTORY/file.log', 'C:\\PRIVATE_DIRECTORY\\file.log', '\\\\server\\PRIVATE_DIRECTORY\\file.log']);
