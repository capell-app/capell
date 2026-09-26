<?php

declare(strict_types=1);

use Capell\Core\Actions\Reporting\DispatchSignalAction;
use Capell\Core\Actions\Reporting\GetReportingHealthAction;
use Capell\Core\Actions\Reporting\GetReportingIncidentAction;
use Capell\Core\Actions\Reporting\ProcessReportingIncidentsAction;
use Capell\Core\Actions\Reporting\PruneReportingIncidentsAction;
use Capell\Core\Actions\Reporting\UpdateReportingIncidentAction;
use Capell\Core\Data\Reporting\ReportingIncidentData;
use Capell\Core\Data\Reporting\SignalData;
use Capell\Core\Enums\Reporting\DispatchStatus;
use Capell\Core\Enums\Reporting\FailureCategory;
use Capell\Core\Enums\Reporting\IncidentStatus;
use Capell\Core\Enums\Reporting\Severity;
use Capell\Core\Models\ReportingIncident;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Log\LogManager;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

beforeEach(function (): void {
    $this->routingRecords = new TestHandler;
    $records = $this->routingRecords;
    $logs = new LogManager($this->app);
    $logs->extend('routing-test', fn (): Logger => new Logger('routing-test', [$records]));

    config()->set('logging.default', 'routing-test');
    config()->set('logging.channels.routing-test', ['driver' => 'routing-test']);

    $this->app->instance(LogManager::class, $logs);
    config()->set('capell-reporting', require __DIR__ . '/../../../config/capell-reporting.php');
    config()->set('capell-reporting.cache_store', 'array');
    config()->set('capell-reporting.defaults.transport', 'operator');
    config()->set('capell-reporting.defaults.channels', ['log', 'health']);
    config()->set('capell-reporting.defaults.owner', 'primary');
    config()->set('capell-reporting.defaults.backup', 'backup');
    config()->set('capell-reporting.defaults.escalate_after_seconds', 60);
    config()->set('capell-reporting.operators', ['primary' => 'operator@example.test', 'backup' => 'backup@example.test']);
});

function operatorSignal(string $correlation = 'trace-1'): SignalData
{
    return new SignalData('import.failed', FailureCategory::Dependency, Severity::Error, 'Import failed.', 'Inspect the source.', $correlation, 'run-1', ['password' => 'routing-secret']);
}

function operatorIncident(SignalData $signal): ReportingIncidentData
{
    return GetReportingIncidentAction::run($signal->fingerprint()) ?? throw new RuntimeException('Expected a persisted incident.');
}

it('records a redacted incident and delivers the opted in log and health channels', function (): void {
    $signal = operatorSignal();
    $result = resolve(DispatchSignalAction::class)->handle($signal);

    expect($result->status)->toBe(DispatchStatus::Reported);

    $incident = operatorIncident($signal);
    expect($incident)->not->toBeNull()
        ->and($incident->status)->toBe(IncidentStatus::Open)
        ->and($incident->owner)->toBe('primary')
        ->and($incident->backup)->toBe('backup')
        ->and($incident->signal->toJson())->not->toContain('routing-secret')
        ->and($incident->deliveries)->toBe(['log' => 'delivered', 'health' => 'delivered'])
        ->and($this->routingRecords->getRecords())->toHaveCount(1);
});

it('deduplicates accepted incident deliveries across dispatchers and cache resets', function (): void {
    $this->freezeTime();
    $signal = operatorSignal();
    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Reported);
    resolve(Factory::class)->store('array')->flush();
    $this->travel(301)->seconds();

    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Suppressed)
        ->and($this->routingRecords->getRecords())->toHaveCount(1);
});

it('acknowledges only assigned operators and keeps acknowledgement durable', function (): void {
    $signal = operatorSignal();
    resolve(DispatchSignalAction::class)->handle($signal);

    expect(UpdateReportingIncidentAction::run($signal->fingerprint(), IncidentStatus::Acknowledged, 'stranger'))->toBeFalse()
        ->and(UpdateReportingIncidentAction::run($signal->fingerprint(), IncidentStatus::Acknowledged, 'primary'))->toBeTrue()
        ->and(UpdateReportingIncidentAction::run($signal->fingerprint(), IncidentStatus::Open, 'primary'))->toBeFalse()
        ->and(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Suppressed);

    $incident = operatorIncident($signal);
    expect($incident->status)->toBe(IncidentStatus::Acknowledged)
        ->and($incident->acknowledgedBy)->toBe('primary')
        ->and($incident->acknowledgedAt)->not->toBeNull();
});

function routingMailTransport(): ArrayTransport
{
    config()->set('mail.mailers.reporting-test', ['transport' => 'array']);
    config()->set('mail.from', ['address' => 'reports@example.test', 'name' => 'Operations']);
    config()->set('capell-reporting.email', ['enabled' => true, 'mailer' => 'reporting-test', 'max_attempts' => 2, 'window_seconds' => 60]);
    config()->set('capell-reporting.defaults.channels', ['log', 'health', 'email']);

    $transport = resolve(Illuminate\Contracts\Mail\Factory::class)->mailer('reporting-test')->getSymfonyTransport();
    throw_unless($transport instanceof ArrayTransport, RuntimeException::class, 'Expected the isolated mail transport.');

    return $transport;
}

it('emails only the configured owner with a redacted payload and durable receipt', function (): void {
    $transport = routingMailTransport();
    $signal = operatorSignal();

    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Reported)
        ->and($transport->messages())->toHaveCount(1);
    $message = $transport->messages()->first()->getOriginalMessage();
    expect($message->getTo()[0]->getAddress())->toBe('operator@example.test')
        ->and($message->getTextBody())->not->toContain('routing-secret')
        ->and(operatorIncident($signal)->deliveries['email'])->toBe('delivered');
});

it('withholds structured credentials from operator mail logs and stored snapshots', function (string $text, bool $fallback): void {
    $transport = routingMailTransport();
    config()->set('capell-reporting.defaults.channels', $fallback ? ['email'] : ['log', 'health', 'email']);
    config()->set('capell-reporting.email.enabled', ! $fallback);

    $signal = new SignalData('import.failed', FailureCategory::Dependency, Severity::Error, $text, $text, 'trace-1', context: ['detail' => $text]);

    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe($fallback ? DispatchStatus::Fallback : DispatchStatus::Reported)
        ->and($this->routingRecords->getRecords())->toHaveCount(1)
        ->and($transport->messages())->toHaveCount($fallback ? 0 : 1);

    $snapshot = ReportingIncident::query()->findOrFail($signal->fingerprint())->getRawOriginal('signal');
    foreach ([$signal->toJson(), $signal->toHuman(), $snapshot, $this->routingRecords->getRecords()[0]->message] as $output) {
        expect($output)->not->toContain('OPERATOR_CREDENTIAL_LEAK');
    }

    if (! $fallback) {
        expect($transport->messages()->first()->getOriginalMessage()->getTextBody())->not->toContain('OPERATOR_CREDENTIAL_LEAK');
    }
})->with([
    'form assignment' => ['password+%3D+OPERATOR_CREDENTIAL_LEAK'],
    'double form assignment' => ['client_secret%2B%253D%2BOPERATOR_CREDENTIAL_LEAK'],
    'nested object' => ['{"password": { "value": "OPERATOR_CREDENTIAL_LEAK" }}'],
    'nested array' => ['{"password": [ "OPERATOR_CREDENTIAL_LEAK" ]}'],
])->with([false, true]);

it('limits email attempts across unrelated incidents until the exact window boundary', function (): void {
    $this->freezeTime();
    $transport = routingMailTransport();
    config()->set('capell-reporting.defaults.cooldown_seconds', 10);
    $dispatch = resolve(DispatchSignalAction::class);
    $dispatch->handle(operatorSignal('trace-1'));
    $dispatch->handle(operatorSignal('trace-2'));

    expect($dispatch->handle(operatorSignal('trace-3'))->status)->toBe(DispatchStatus::Fallback)
        ->and($transport->messages())->toHaveCount(2)
        ->and(operatorIncident(operatorSignal('trace-3'))->deliveries['email'])->toBe('rate_limited');
    $this->travel(59)->seconds();
    expect($dispatch->handle(operatorSignal('trace-4'))->status)->toBe(DispatchStatus::Fallback);
    $this->travel(1)->seconds();
    expect($dispatch->handle(operatorSignal('trace-5'))->status)->toBe(DispatchStatus::Reported)
        ->and($transport->messages())->toHaveCount(3);
});

it('escalates once to the backup at the deadline even during the owner cooldown', function (): void {
    $this->freezeTime();
    $transport = routingMailTransport();
    $signal = operatorSignal();
    resolve(DispatchSignalAction::class)->handle($signal);
    $this->travel(59)->seconds();
    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Suppressed);
    $this->travel(1)->seconds();

    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Reported)
        ->and($transport->messages())->toHaveCount(2)
        ->and($transport->messages()->last()->getOriginalMessage()->getTo()[0]->getAddress())->toBe('backup@example.test')
        ->and(operatorIncident($signal)->status)->toBe(IncidentStatus::Escalated)
        ->and(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Suppressed);
});

it('does not escalate acknowledged incidents after the deadline', function (): void {
    $this->freezeTime();
    $transport = routingMailTransport();
    $signal = operatorSignal();
    resolve(DispatchSignalAction::class)->handle($signal);
    UpdateReportingIncidentAction::run($signal->fingerprint(), IncidentStatus::Acknowledged, 'primary');
    $this->travel(1000)->seconds();

    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Suppressed)
        ->and($transport->messages())->toHaveCount(1);
});

it('exposes only aggregate health and resolves without reopening the same incident', function (): void {
    config()->set('capell-reporting.health.enabled', true);
    $signal = operatorSignal();
    resolve(DispatchSignalAction::class)->handle($signal);

    $this->getJson('/_capell/reporting/health')->assertStatus(503)->assertExactJson([
        'status' => 'degraded', 'unresolved' => 1, 'acknowledged' => 0, 'escalated' => 0, 'delivery_failures' => 0,
    ])->assertHeader('Cache-Control', 'no-store, private');
    expect(UpdateReportingIncidentAction::run($signal->fingerprint(), IncidentStatus::Resolved, 'primary'))->toBeTrue()
        ->and(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Suppressed);
    $this->getJson('/_capell/reporting/health')->assertOk()->assertJsonPath('unresolved', 0);
    config()->set('capell-reporting.health.enabled', false);
    $this->getJson('/_capell/reporting/health')->assertNotFound();
});

it('processes due escalations without requiring another occurrence of the signal', function (): void {
    $this->freezeTime();
    $transport = routingMailTransport();
    $signal = operatorSignal();
    resolve(DispatchSignalAction::class)->handle($signal);
    $this->travel(60)->seconds();

    expect(ProcessReportingIncidentsAction::run())->toBe(['reported' => 1])
        ->and($transport->messages())->toHaveCount(2)
        ->and(ProcessReportingIncidentsAction::run())->toBe(['suppressed' => 1])
        ->and($transport->messages())->toHaveCount(2);
});

it('prunes only resolved incidents older than the retention boundary', function (): void {
    $this->freezeTime();
    $resolved = operatorSignal('resolved');
    $open = operatorSignal('unresolved');
    resolve(DispatchSignalAction::class)->handle($resolved);
    resolve(DispatchSignalAction::class)->handle($open);
    UpdateReportingIncidentAction::run($resolved->fingerprint(), IncidentStatus::Resolved, 'primary');

    expect(PruneReportingIncidentsAction::run(30))->toBe(0);
    $this->travel(30)->days();
    expect(PruneReportingIncidentsAction::run(30))->toBe(1)
        ->and(GetReportingIncidentAction::run($resolved->fingerprint()))->toBeNull()
        ->and(GetReportingIncidentAction::run($open->fingerprint()))->not->toBeNull();
});

it('reports partial delivery when email succeeds but both log attempts fail', function (): void {
    $transport = routingMailTransport();
    $this->app->make(LogManager::class)->extend('broken-routing', function (): never {
        throw new RuntimeException('password=transport-secret');
    });
    config()->set('logging.default', 'broken-routing');
    config()->set('logging.channels.broken-routing', ['driver' => 'broken-routing']);

    $result = resolve(DispatchSignalAction::class)->handle(operatorSignal());
    expect($result->status->value)->toBe('partial')
        ->and($transport->messages())->toHaveCount(1)
        ->and(operatorIncident(operatorSignal())->deliveries)->toBe(['log' => 'unavailable', 'health' => 'delivered', 'email' => 'delivered', 'fallback_log' => 'unavailable']);
});

it('keeps failed email retryable after cooldown without repeating accepted log delivery', function (): void {
    $this->freezeTime();
    $transport = routingMailTransport();
    config()->set('capell-reporting.defaults.backup');
    config()->set('capell-reporting.defaults.cooldown_seconds', 10);
    config()->set('capell-reporting.email.mailer', 'missing');

    $signal = operatorSignal();
    $dispatch = resolve(DispatchSignalAction::class);

    expect($dispatch->handle($signal)->status)->toBe(DispatchStatus::Fallback)
        ->and(operatorIncident($signal)->deliveries['email'])->toBe('unavailable');
    config()->set('capell-reporting.email.mailer', 'reporting-test');
    $this->travel(9)->seconds();
    expect($dispatch->handle($signal)->status)->toBe(DispatchStatus::Suppressed)
        ->and($transport->messages())->toHaveCount(0);
    $this->travel(1)->seconds();
    expect($dispatch->handle($signal)->status)->toBe(DispatchStatus::Reported)
        ->and($transport->messages())->toHaveCount(1)
        ->and($this->routingRecords->getRecords())->toHaveCount(1);
});

it('counts failed and cancelled mail attempts against the shared quota', function (): void {
    $transport = routingMailTransport();
    config()->set('capell-reporting.email.max_attempts', 1);
    Event::listen(MessageSending::class, fn (): bool => false);
    expect(resolve(DispatchSignalAction::class)->handle(operatorSignal())->status)->toBe(DispatchStatus::Fallback)
        ->and(operatorIncident(operatorSignal())->deliveries['email'])->toBe('unavailable');
    Event::forget(MessageSending::class);
    expect(resolve(DispatchSignalAction::class)->handle(operatorSignal('next'))->status)->toBe(DispatchStatus::Fallback)
        ->and(operatorIncident(operatorSignal('next'))->deliveries['email'])->toBe('rate_limited')
        ->and($transport->messages())->toHaveCount(0);
});

it('records fallback logs once while email only incidents remain retryable', function (string $failure): void {
    $this->freezeTime();
    $transport = routingMailTransport();
    config()->set('capell-reporting.defaults.channels', ['email']);
    config()->set('capell-reporting.defaults.backup');
    config()->set('capell-reporting.defaults.cooldown_seconds', 10);
    if ($failure === 'disabled') {
        config()->set('capell-reporting.email.enabled', false);
    } elseif ($failure === 'unavailable') {
        config()->set('capell-reporting.email.mailer', 'missing');
    } else {
        resolve(Factory::class)->store('array')->put('capell:reporting:email-quota', ['attempts' => 2, 'expires_at' => now()->addMinutes(10)->getTimestamp()], 600);
    }

    $signal = operatorSignal();
    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Fallback);
    $this->travel(10)->seconds();
    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Fallback)
        ->and($this->routingRecords->getRecords())->toHaveCount(1)
        ->and(operatorIncident($signal)->deliveries)->toBe(['email' => $failure, 'fallback_log' => 'delivered']);

    config()->set('capell-reporting.email.enabled', true);
    config()->set('capell-reporting.email.mailer', 'reporting-test');
    resolve(Factory::class)->store('array')->forget('capell:reporting:email-quota');
    $this->travel(10)->seconds();
    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Reported)
        ->and($transport->messages())->toHaveCount(1)
        ->and(operatorIncident($signal)->deliveries)->toBe(['email' => 'delivered', 'fallback_log' => 'delivered'])
        ->and($this->routingRecords->getRecords())->toHaveCount(1);
})->with(['disabled', 'unavailable', 'rate_limited']);

it('deduplicates fallback logs separately for owner and backup delivery stages', function (): void {
    $this->freezeTime();
    $transport = routingMailTransport();
    config()->set('capell-reporting.defaults.channels', ['email']);
    config()->set('capell-reporting.defaults.cooldown_seconds', 10);
    config()->set('capell-reporting.email.enabled', false);

    $signal = operatorSignal();

    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Fallback);
    $this->travel(60)->seconds();
    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Fallback)
        ->and($this->routingRecords->getRecords())->toHaveCount(2);
    resolve(Factory::class)->store('array')->flush();
    $this->travel(10)->seconds();
    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Fallback)
        ->and($this->routingRecords->getRecords())->toHaveCount(2)
        ->and(operatorIncident($signal)->deliveries)->toBe([
            'email' => 'disabled', 'fallback_log' => 'delivered',
            'escalation_email' => 'disabled', 'escalation_fallback_log' => 'delivered',
        ]);

    config()->set('capell-reporting.email.enabled', true);
    $this->travel(10)->seconds();
    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Reported)
        ->and($transport->messages())->toHaveCount(1)
        ->and($transport->messages()->first()->getOriginalMessage()->getTo()[0]->getAddress())->toBe('backup@example.test')
        ->and($this->routingRecords->getRecords())->toHaveCount(2);
});

it('retries unavailable fallback logging and then retains its accepted receipt', function (): void {
    $this->freezeTime();
    config()->set('capell-reporting.defaults.channels', ['email']);
    config()->set('capell-reporting.defaults.backup');
    config()->set('capell-reporting.defaults.cooldown_seconds', 10);
    config()->set('logging.default', 'broken-fallback');
    config()->set('logging.channels.broken-fallback', ['driver' => 'broken-fallback']);
    $this->app->make(LogManager::class)->extend('broken-fallback', fn (): never => throw new RuntimeException('Fallback is unavailable.'));
    $signal = operatorSignal();

    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Failed)
        ->and(operatorIncident($signal)->deliveries)->toBe(['email' => 'disabled', 'fallback_log' => 'unavailable']);
    config()->set('logging.default', 'routing-test');
    $this->travel(10)->seconds();
    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Fallback)
        ->and(operatorIncident($signal)->deliveries)->toBe(['email' => 'disabled', 'fallback_log' => 'delivered']);
    $this->travel(10)->seconds();
    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Fallback)
        ->and($this->routingRecords->getRecords())->toHaveCount(1);
});

it('retains health and safe logs when the mail quota store is unavailable', function (): void {
    $transport = routingMailTransport();
    config()->set('capell-reporting.cache_store', 'missing');
    config()->set('capell-reporting.health.enabled', true);

    expect(resolve(DispatchSignalAction::class)->handle(operatorSignal())->status)->toBe(DispatchStatus::Fallback)
        ->and($transport->messages())->toHaveCount(0)
        ->and($this->routingRecords->getRecords())->toHaveCount(1);
    $this->getJson('/_capell/reporting/health')->assertStatus(503)->assertJsonPath('delivery_failures', 1);
});

it('falls back safely before any email when incident storage is unavailable', function (): void {
    $transport = routingMailTransport();
    config()->set('capell-reporting.health.enabled', true);
    Schema::drop('capell_reporting_incidents');

    expect(resolve(DispatchSignalAction::class)->handle(operatorSignal())->status)->toBe(DispatchStatus::Fallback)
        ->and($transport->messages())->toHaveCount(0)
        ->and($this->routingRecords->getRecords())->toHaveCount(1);
    $this->getJson('/_capell/reporting/health')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
    config()->set('capell-reporting.enabled', false);
    $this->getJson('/_capell/reporting/health')->assertNotFound();
});

it('applies category and exact signal routing overrides while unknown names inherit defaults', function (): void {
    $transport = routingMailTransport();
    config()->set('capell-reporting.defaults.channels', ['health']);
    config()->set('capell-reporting.categories', ['dependency' => ['channels' => ['health', 'email'], 'owner' => 'backup']]);
    config()->set('capell-reporting.signals', ['import.failed' => ['owner' => 'primary']]);

    resolve(DispatchSignalAction::class)->handle(operatorSignal());
    $unknown = new SignalData('unlisted.failure', FailureCategory::Dependency, Severity::Error, 'Failed.', 'Inspect.', 'trace-2');
    resolve(DispatchSignalAction::class)->handle($unknown);

    expect($transport->messages())->toHaveCount(2)
        ->and($transport->messages()->first()->getOriginalMessage()->getTo()[0]->getAddress())->toBe('operator@example.test')
        ->and($transport->messages()->last()->getOriginalMessage()->getTo()[0]->getAddress())->toBe('backup@example.test')
        ->and($this->routingRecords->getRecords())->toHaveCount(0);
});

it('honours global and exact signal disablement without state or email', function (bool $global): void {
    $transport = routingMailTransport();
    if ($global) {
        config()->set('capell-reporting.enabled', false);
        config()->set('capell-reporting.defaults', 'invalid');
    } else {
        config()->set('capell-reporting.signals', ['import.failed' => ['enabled' => false, 'channels' => 'invalid']]);
    }

    expect(resolve(DispatchSignalAction::class)->handle(operatorSignal())->status)->toBe(DispatchStatus::Disabled)
        ->and(GetReportingIncidentAction::run(operatorSignal()->fingerprint()))->toBeNull()
        ->and($transport->messages())->toHaveCount(0)
        ->and($this->routingRecords->getRecords())->toHaveCount(0);
})->with([true, false]);

it('rejects unknown channels and malformed routing before sending email', function (array $override): void {
    $transport = routingMailTransport();
    config()->set('capell-reporting.signals', ['import.failed' => $override]);

    expect(resolve(DispatchSignalAction::class)->handle(operatorSignal())->status)->toBe(DispatchStatus::Fallback)
        ->and($transport->messages())->toHaveCount(0)
        ->and(GetReportingIncidentAction::run(operatorSignal()->fingerprint()))->toBeNull();
})->with([
    [['channels' => ['webhook']]],
    [['channels' => []]],
    [['owner' => "operator\r\nBcc: foreign@example.test"]],
    [['escalate_after_seconds' => -1]],
]);

it('honours an exact disable before validating malformed inherited policies', function (string $key, mixed $value): void {
    $transport = routingMailTransport();
    config()->set('capell-reporting.' . $key, $value);
    config()->set('capell-reporting.signals', ['import.failed' => ['enabled' => false]]);

    $signal = operatorSignal();
    $store = resolve(Factory::class)->store('array')->getStore();
    $this->assertInstanceOf(ArrayStore::class, $store);

    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Disabled)
        ->and(GetReportingIncidentAction::run($signal->fingerprint()))->toBeNull()
        ->and($transport->messages())->toHaveCount(0)
        ->and($this->routingRecords->getRecords())->toHaveCount(0)
        ->and($store->locks)->toBe([]);
})->with([
    'defaults' => ['defaults', 'invalid'],
    'categories' => ['categories', 'invalid'],
    'selected category' => ['categories.dependency', 'invalid'],
    'global flag' => ['enabled', 'invalid'],
    'default flag' => ['defaults.enabled', 'invalid'],
    'category flag' => ['categories.dependency.enabled', 'invalid'],
    'services' => ['reporters', 'invalid'],
]);

it('honours a category disable over malformed defaults unless the exact policy overrides it', function (bool $reenable): void {
    $transport = routingMailTransport();
    config()->set('capell-reporting.defaults', 'invalid');
    config()->set('capell-reporting.categories', ['dependency' => ['enabled' => false]]);
    config()->set('capell-reporting.signals', ['import.failed' => $reenable ? ['enabled' => true] : ['owner' => 'primary']]);

    $signal = operatorSignal();

    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe($reenable ? DispatchStatus::Fallback : DispatchStatus::Disabled)
        ->and(GetReportingIncidentAction::run($signal->fingerprint()))->toBeNull()
        ->and($transport->messages())->toHaveCount(0)
        ->and($this->routingRecords->getRecords())->toHaveCount($reenable ? 1 : 0);
})->with([false, true]);

it('stops disabled email without using its quota and can deliver after explicit enablement', function (): void {
    $this->freezeTime();
    $transport = routingMailTransport();
    config()->set('capell-reporting.defaults.backup');
    config()->set('capell-reporting.defaults.cooldown_seconds', 1);
    config()->set('capell-reporting.email.enabled', false);
    config()->set('capell-reporting.email.max_attempts', 1);
    expect(resolve(DispatchSignalAction::class)->handle(operatorSignal())->status)->toBe(DispatchStatus::Fallback)
        ->and(operatorIncident(operatorSignal())->deliveries['email'])->toBe('disabled');
    config()->set('capell-reporting.email.enabled', true);
    $this->travel(1)->seconds();
    expect(resolve(DispatchSignalAction::class)->handle(operatorSignal())->status)->toBe(DispatchStatus::Reported)
        ->and($transport->messages())->toHaveCount(1);
});

it('does not overwrite acknowledgement made while an email is in flight', function (): void {
    $transport = routingMailTransport();
    $signal = operatorSignal();
    Event::listen(MessageSending::class, function () use ($signal): void {
        UpdateReportingIncidentAction::run($signal->fingerprint(), IncidentStatus::Acknowledged, 'primary');
    });

    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Reported)
        ->and(operatorIncident($signal)->status)->toBe(IncidentStatus::Acknowledged)
        ->and($transport->messages())->toHaveCount(1);
});

it('refuses corrupted quota counters before any outbound email', function (): void {
    $transport = routingMailTransport();
    resolve(Factory::class)->store('array')->put('capell:reporting:email-quota', ['attempts' => -100, 'expires_at' => now()->addMinute()->getTimestamp()], 60);

    expect(resolve(DispatchSignalAction::class)->handle(operatorSignal())->status)->toBe(DispatchStatus::Fallback)
        ->and($transport->messages())->toHaveCount(0)
        ->and(operatorIncident(operatorSignal())->deliveries['email'])->toBe('unavailable');
});

it('returns unavailable health without exposing configuration failures', function (): void {
    $configuration = Mockery::mock(Repository::class);
    $configuration->shouldReceive('get')->andThrow(new RuntimeException('password=health-configuration-secret'));
    $this->app->when(GetReportingHealthAction::class)
        ->needs(Repository::class)->give(fn (): Repository => $configuration);

    $this->getJson('/_capell/reporting/health')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
});

it('suppresses a concurrent fibre through the durable delivery claim', function (): void {
    $transport = routingMailTransport();
    config()->set('capell-reporting.defaults.cooldown_seconds', 0);
    $signal = operatorSignal();
    $reason = null;
    Event::listen(MessageSending::class, function () use ($signal, &$reason): void {
        $fibre = new Fiber(function () use ($signal, &$reason): void {
            $reason = resolve(DispatchSignalAction::class)->handle($signal)->reason;
        });
        $fibre->start();
    });

    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Reported)
        ->and($reason)->toBe('delivery_in_progress')
        ->and($transport->messages())->toHaveCount(1);
});

it('does not count owner delivery as successful backup delivery when every escalation channel fails', function (): void {
    $this->freezeTime();
    routingMailTransport();
    config()->set('capell-reporting.defaults.channels', ['log', 'email']);
    $signal = operatorSignal();
    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Reported);
    $this->travel(60)->seconds();
    config()->set('capell-reporting.email.mailer', 'missing');
    $this->app->make(LogManager::class)->extend('broken-escalation', function (): never {
        throw new RuntimeException('Escalation log unavailable.');
    });
    config()->set('logging.default', 'broken-escalation');
    config()->set('logging.channels.broken-escalation', ['driver' => 'broken-escalation']);

    expect(resolve(DispatchSignalAction::class)->handle($signal)->status)->toBe(DispatchStatus::Failed)
        ->and(operatorIncident($signal)->deliveries['email'])->toBe('delivered')
        ->and(operatorIncident($signal)->deliveries['escalation_email'])->toBe('unavailable');
});
