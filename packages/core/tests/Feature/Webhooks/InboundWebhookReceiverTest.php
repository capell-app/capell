<?php

declare(strict_types=1);

use Capell\Core\Enums\InboundWebhookEventStatus;
use Capell\Core\Jobs\Webhooks\ProcessInboundWebhookEventJob;
use Capell\Core\Models\InboundWebhookEvent;
use Capell\Core\Support\Webhooks\HmacTimestampSignature;
use Capell\Core\Support\Webhooks\InboundWebhookReceiver;
use Capell\Core\Tests\CoreTestCase;
use Capell\Core\Tests\Feature\Webhooks\Fixtures\RecordingInboundWebhookHandler;
use Capell\Core\Tests\Feature\Webhooks\Fixtures\TestInboundWebhookProvider;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * @param  array<string, mixed>  $event
 */
function deliverTestWebhook(CoreTestCase $test, array $event, ?string $signature = null, string $contentType = 'application/json'): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);

    return $test->call('POST', '/test-webhooks/provider', server: [
        'CONTENT_TYPE' => $contentType,
        'HTTP_X_TEST_SIGNATURE' => $signature ?? HmacTimestampSignature::sign($body, TestInboundWebhookProvider::SECRET, Date::now()->getTimestamp()),
    ], content: $body);
}

beforeEach(function (): void {
    RecordingInboundWebhookHandler::$handled = [];
    Route::middleware('web')->group(fn () => Route::capellWebhook('test-webhooks/provider', TestInboundWebhookProvider::class));
});

it('registers a POST-only webhook route that skips CSRF and is throttled', function (): void {
    $route = Route::getRoutes()->match(Request::create('/test-webhooks/provider', Symfony\Component\HttpFoundation\Request::METHOD_POST));
    $middleware = Route::gatherRouteMiddleware($route);

    expect($route->methods())->toBe(['POST'])
        ->and(collect($middleware)->contains(fn (mixed $name): bool => is_string($name) && is_a($name, PreventRequestForgery::class, true)))->toBeFalse()
        ->and(collect($middleware)->contains(fn (mixed $name): bool => is_string($name) && str_contains($name, 'ThrottleRequests')))->toBeTrue();
});

it('refuses to register a webhook route for a class that is not a provider', function (): void {
    expect(fn () => Route::capellWebhook('test-webhooks/other', stdClass::class))->toThrow(InvalidArgumentException::class);
});

it('stores a verified event once and queues its handler once', function (): void {
    Queue::fake();

    deliverTestWebhook($this, ['id' => 'evt_1', 'type' => 'order.paid', 'created' => 1_800_000_000, 'subject' => 'order_9'])
        ->assertStatus(202)
        ->assertExactJson(['ok' => true]);

    $event = InboundWebhookEvent::query()->sole();
    expect($event->provider)->toBe('tests.provider')
        ->and($event->status)->toBe(InboundWebhookEventStatus::Received)
        ->and($event->subject)->toBe('order_9')
        ->and($event->payload['id'] ?? null)->toBe('evt_1');
    Queue::assertPushed(ProcessInboundWebhookEventJob::class, 1);
});

it('acknowledges a redelivered or replayed event without storing or queueing it again', function (): void {
    Queue::fake();
    $event = ['id' => 'evt_2', 'type' => 'order.paid', 'created' => 1_800_000_000];

    deliverTestWebhook($this, $event)->assertStatus(202);
    deliverTestWebhook($this, $event)->assertOk()->assertExactJson(['ok' => true, 'duplicate' => true]);
    deliverTestWebhook($this, [...$event, 'attempt' => 2])->assertOk()->assertExactJson(['ok' => true, 'duplicate' => true]);

    expect(InboundWebhookEvent::query()->count())->toBe(1);
    Queue::assertPushed(ProcessInboundWebhookEventJob::class, 1);
});

it('refuses an unsigned, forged, stale or non-JSON delivery with a bare response and stores nothing', function (string $case, int $status): void {
    Queue::fake();
    $event = ['id' => 'evt_3', 'type' => 'order.paid', 'created' => 1];
    $body = json_encode($event, JSON_THROW_ON_ERROR);

    $response = match ($case) {
        'unsigned' => deliverTestWebhook($this, $event, ''),
        'forged' => deliverTestWebhook($this, $event, HmacTimestampSignature::sign($body, 'guess', Date::now()->getTimestamp())),
        'stale capture' => deliverTestWebhook($this, $event, HmacTimestampSignature::sign($body, TestInboundWebhookProvider::SECRET, Date::now()->subHours(1)->getTimestamp())),
        'form post' => deliverTestWebhook($this, $event, contentType: 'application/x-www-form-urlencoded'),
        default => throw new InvalidArgumentException('Unknown delivery case.'),
    };

    $response->assertStatus($status)->assertExactJson(['ok' => false]);
    expect(InboundWebhookEvent::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'unsigned' => ['unsigned', 400],
    'forged' => ['forged', 400],
    'stale capture' => ['stale capture', 400],
    'form post' => ['form post', 415],
]);

it('records a verified event of an unmapped type as ignored and queues nothing', function (): void {
    Queue::fake();

    deliverTestWebhook($this, ['id' => 'evt_4', 'type' => 'order.unknown', 'created' => 1_800_000_000])->assertStatus(202);

    expect(InboundWebhookEvent::query()->sole()->status)->toBe(InboundWebhookEventStatus::Ignored);
    Queue::assertNothingPushed();
});

it('runs the handler once even when the job is delivered twice', function (): void {
    deliverTestWebhook($this, ['id' => 'evt_5', 'type' => 'order.paid', 'created' => 1_800_000_000])->assertStatus(202);
    $event = InboundWebhookEvent::query()->sole();

    new ProcessInboundWebhookEventJob((int) $event->getKey())->handle();

    expect(RecordingInboundWebhookHandler::$handled)->toBe([['event' => 'evt_5', 'stale' => false]])
        ->and($event->refresh()->status)->toBe(InboundWebhookEventStatus::Processed)
        ->and($event->attempts)->toBe(1)
        ->and($event->processed_at)->not->toBeNull();
});

it('records a failed handler by exception class only and lets the queue retry it', function (): void {
    Queue::fake();
    deliverTestWebhook($this, ['id' => 'evt_6', 'type' => 'order.broken', 'created' => 1_800_000_000])->assertStatus(202);
    $event = InboundWebhookEvent::query()->sole();

    expect(fn () => new ProcessInboundWebhookEventJob((int) $event->getKey())->handle())->toThrow(RuntimeException::class);

    $event->refresh();
    expect($event->status)->toBe(InboundWebhookEventStatus::Failed)
        ->and($event->last_error)->toBe(RuntimeException::class)
        ->and($event->attempts)->toBe(1);
    expect(fn () => new ProcessInboundWebhookEventJob((int) $event->getKey())->handle())->toThrow(RuntimeException::class);
    expect($event->refresh()->attempts)->toBe(2);
});

it('tells a handler when a newer event about the same subject was already processed', function (): void {
    deliverTestWebhook($this, ['id' => 'evt_new', 'type' => 'order.paid', 'created' => 1_800_000_100, 'subject' => 'order_1']);
    deliverTestWebhook($this, ['id' => 'evt_old', 'type' => 'order.paid', 'created' => 1_800_000_000, 'subject' => 'order_1']);
    deliverTestWebhook($this, ['id' => 'evt_other', 'type' => 'order.paid', 'created' => 1_800_000_000, 'subject' => 'order_2']);

    expect(RecordingInboundWebhookHandler::$handled)->toBe([
        ['event' => 'evt_new', 'stale' => false],
        ['event' => 'evt_old', 'stale' => true],
        ['event' => 'evt_other', 'stale' => false],
    ]);
});

it('encrypts the stored payload', function (): void {
    Queue::fake();
    deliverTestWebhook($this, ['id' => 'evt_7', 'type' => 'order.paid', 'created' => 1_800_000_000, 'email' => 'jane@example.test']);

    $raw = DB::table((new InboundWebhookEvent)->getTable())->value('payload');

    expect($raw)->toBeString()->not->toContain('jane@example.test');
});

it('replays a failed event, and a processed one only when forced', function (): void {
    Queue::fake();
    deliverTestWebhook($this, ['id' => 'evt_8', 'type' => 'order.paid', 'created' => 1_800_000_000]);
    deliverTestWebhook($this, ['id' => 'evt_9', 'type' => 'order.unknown', 'created' => 1_800_000_000]);
    $event = InboundWebhookEvent::query()->where('event_id', 'evt_8')->sole();
    $ignored = InboundWebhookEvent::query()->where('event_id', 'evt_9')->sole();

    new UniqueLock(cache()->store())->release(new ProcessInboundWebhookEventJob((int) $event->getKey()));
    $event->forceFill(['status' => InboundWebhookEventStatus::Failed])->save();
    expect(Artisan::call('capell:webhooks:replay', ['event' => (string) $event->getKey()]))->toBe(0);
    expect($event->refresh()->status)->toBe(InboundWebhookEventStatus::Received);

    // The queued copy has run; its unique lock is released as the worker would.
    new UniqueLock(cache()->store())->release(new ProcessInboundWebhookEventJob((int) $event->getKey()));
    $event->forceFill(['status' => InboundWebhookEventStatus::Processed])->save();
    expect(Artisan::call('capell:webhooks:replay', ['event' => (string) $event->getKey()]))->toBe(1);
    expect(Artisan::call('capell:webhooks:replay', ['event' => (string) $event->getKey(), '--force' => true]))->toBe(0);
    expect(Artisan::call('capell:webhooks:replay', ['event' => (string) $ignored->getKey()]))->toBe(1);
    expect(Artisan::call('capell:webhooks:replay', ['event' => '999999']))->toBe(1);

    Queue::assertPushed(ProcessInboundWebhookEventJob::class, 3);
});

it('prunes finished events after the retention period and keeps failed ones', function (): void {
    Queue::fake();
    deliverTestWebhook($this, ['id' => 'evt_old_done', 'type' => 'order.unknown', 'created' => 1_800_000_000]);
    deliverTestWebhook($this, ['id' => 'evt_old_failed', 'type' => 'order.paid', 'created' => 1_800_000_000]);
    deliverTestWebhook($this, ['id' => 'evt_recent', 'type' => 'order.unknown', 'created' => 1_800_000_000]);
    InboundWebhookEvent::query()->where('event_id', 'evt_old_failed')->update(['status' => InboundWebhookEventStatus::Failed->value]);
    InboundWebhookEvent::query()->whereIn('event_id', ['evt_old_done', 'evt_old_failed'])->update(['updated_at' => now()->subDays(InboundWebhookEvent::RETENTION_DAYS + 1)]);

    expect(Artisan::call('model:prune', ['--model' => [InboundWebhookEvent::class]]))->toBe(0);

    expect(InboundWebhookEvent::query()->orderBy('event_id')->pluck('event_id')->all())->toBe(['evt_old_failed', 'evt_recent']);
});

it('schedules the daily prune of finished webhook events', function (): void {
    $commands = collect(resolve(Schedule::class)->events())
        ->map(fn (ScheduleEvent $event): string => (string) $event->command);

    expect($commands->contains(fn (string $command): bool => str_contains($command, 'model:prune') && str_contains($command, 'InboundWebhookEvent')))->toBeTrue();
});

it('queues a stored event again when a redelivery finds its first queue push failed', function (): void {
    Queue::extend('capell-failing-webhook-queue', fn (): SyncQueue => new class extends SyncQueue
    {
        public function push($job, $data = '', $queue = null): mixed
        {
            throw new RuntimeException('Queue unavailable.');
        }
    });
    config()->set('queue.connections.capell-failing-webhook-queue', ['driver' => 'capell-failing-webhook-queue']);
    config()->set('queue.default', 'capell-failing-webhook-queue');

    $event = ['id' => 'evt_lost_push', 'type' => 'order.paid', 'created' => 1_800_000_000];

    deliverTestWebhook($this, $event)->assertServerError();
    expect(InboundWebhookEvent::query()->sole()->status)->toBe(InboundWebhookEventStatus::Received)
        ->and(RecordingInboundWebhookHandler::$handled)->toBe([]);

    config()->set('queue.default', 'sync');
    deliverTestWebhook($this, $event)->assertOk()->assertExactJson(['ok' => true, 'duplicate' => true]);
    deliverTestWebhook($this, $event)->assertOk();

    expect(RecordingInboundWebhookHandler::$handled)->toBe([['event' => 'evt_lost_push', 'stale' => false]])
        ->and(InboundWebhookEvent::query()->sole()->status)->toBe(InboundWebhookEventStatus::Processed);
});

it('refuses a body over the size limit before verifying or decoding it', function (): void {
    Queue::fake();
    $event = ['id' => 'evt_big', 'type' => 'order.paid', 'created' => 1_800_000_000, 'padding' => str_repeat('a', InboundWebhookReceiver::MAX_BODY_BYTES)];

    deliverTestWebhook($this, $event)->assertStatus(413)->assertExactJson(['ok' => false]);

    $small = json_encode(['id' => 'evt_declared', 'type' => 'order.paid', 'created' => 1_800_000_000], JSON_THROW_ON_ERROR);
    $this->call('POST', '/test-webhooks/provider', server: [
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => (string) (InboundWebhookReceiver::MAX_BODY_BYTES + 1),
        'HTTP_X_TEST_SIGNATURE' => HmacTimestampSignature::sign($small, TestInboundWebhookProvider::SECRET, Date::now()->getTimestamp()),
    ], content: $small)->assertStatus(413);

    expect(InboundWebhookEvent::query()->count())->toBe(0);
});

it('answers a signed event the provider cannot describe with a bare 400, not a server error', function (): void {
    Queue::fake();

    deliverTestWebhook($this, ['id' => str_repeat('e', 300), 'type' => 'order.paid', 'created' => 1_800_000_000])
        ->assertStatus(400)
        ->assertExactJson(['ok' => false]);

    expect(InboundWebhookEvent::query()->count())->toBe(0);
});

it('says so instead of claiming a replay was queued when a copy is already queued', function (): void {
    Queue::fake();
    deliverTestWebhook($this, ['id' => 'evt_locked', 'type' => 'order.paid', 'created' => 1_800_000_000]);
    $event = InboundWebhookEvent::query()->sole();
    $event->forceFill(['status' => InboundWebhookEventStatus::Failed])->save();

    expect(Artisan::call('capell:webhooks:replay', ['event' => (string) $event->getKey()]))->toBe(1)
        ->and(Artisan::output())->toContain(__('capell-core::message.webhook_replay_already_queued'))
        ->and($event->refresh()->status)->toBe(InboundWebhookEventStatus::Failed);
    Queue::assertPushed(ProcessInboundWebhookEventJob::class, 1);
});

it('leaves an event failed and logs it by id and class once the queue gives up', function (): void {
    Queue::fake();
    $logged = [];
    Log::listen(function (object $message) use (&$logged): void {
        $logged[] = json_encode([$message->level, $message->context], JSON_THROW_ON_ERROR);
    });
    deliverTestWebhook($this, ['id' => 'evt_exhausted', 'type' => 'order.broken', 'created' => 1_800_000_000]);
    $event = InboundWebhookEvent::query()->sole();
    $event->forceFill(['status' => InboundWebhookEventStatus::Processing])->save();

    new ProcessInboundWebhookEventJob((int) $event->getKey())->failed(new RuntimeException('card 4242 declined for jane@example.test'));

    expect($event->refresh()->status)->toBe(InboundWebhookEventStatus::Failed);
    expect($logged)->toContain(json_encode(['error', ['event' => $event->getKey(), 'exception' => RuntimeException::class]], JSON_THROW_ON_ERROR))
        ->and(implode("\n", $logged))->not->toContain('jane@example.test');
});
