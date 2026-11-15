<?php

declare(strict_types=1);

use Capell\Core\Actions\HostMonitor\RecordHostSampleAction;
use Capell\Core\Contracts\Health\HostMetricsSource;
use Capell\Core\Data\Diagnostics\HostSampleData;
use Capell\Core\Enums\Diagnostics\HostMonitorStatus;
use Capell\Core\Notifications\HostMonitorAlertNotification;
use Capell\Core\Notifications\HostMonitorWebhookChannel;
use Capell\Core\Support\HostMonitor\HostMonitorStore;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/HostMonitorTestSupport.php';

beforeEach(function (): void {
    Cache::flush();
    Notification::fake();
    Config::set('capell.node_id', 'test-node');
    Config::set('capell.health.host.enabled', true);
    Config::set('capell.health.host.sustained_samples', 2);
    Config::set('capell.health.host.notifications.enabled', true);
    Config::set('capell.health.host.notifications.channels', ['mail']);
    Config::set('capell.health.host.notifications.mail_to', ['ops@example.com']);
    Config::set('capell.health.host.notifications.reminder_minutes', 60);
});

function runMonitor(): void
{
    resolve(RecordHostSampleAction::class)->handle();
}

it('records nothing when monitoring is turned off', function (): void {
    Config::set('capell.health.host.enabled', false);
    fakeHostSource(busySample());

    runMonitor();

    expect(resolve(HostMonitorStore::class)->samples('test-node'))->toBe([]);
});

it('keeps a bounded history of readings', function (): void {
    Config::set('capell.health.host.history_samples', 3);
    fakeHostSource(hostSample());

    for ($i = 0; $i < 6; $i++) {
        runMonitor();
    }

    expect(resolve(HostMonitorStore::class)->samples('test-node'))->toHaveCount(3)
        ->and(resolve(HostMonitorStore::class)->nodes())->toBe(['test-node']);
});

it('sends nothing by default, however busy the server is', function (): void {
    Config::set('capell.health.host.notifications.enabled', false);
    fakeHostSource(busySample());

    runMonitor();
    runMonitor();
    runMonitor();

    Notification::assertNothingSent();
    expect(resolve(HostMonitorStore::class)->state('test-node')['status'])->toBe(HostMonitorStatus::Critical->value);
});

it('emails once when a problem is sustained, not on every reading', function (): void {
    fakeHostSource(busySample());

    runMonitor();
    Notification::assertNothingSent();

    runMonitor();
    runMonitor();
    runMonitor();

    Notification::assertSentOnDemandTimes(HostMonitorAlertNotification::class, 1);
    Notification::assertSentOnDemand(
        HostMonitorAlertNotification::class,
        static fn (HostMonitorAlertNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $channels === ['mail']
            && $notifiable->routes['mail'] === ['ops@example.com']
            && str_contains($notification->subject(), 'test-node')
            && str_contains(implode(' ', $notification->lines()), 'php artisan queue:work'),
    );
});

it('sends a reminder only after the reminder interval', function (): void {
    fakeHostSource(busySample());

    runMonitor();
    runMonitor();
    $this->travel(59)->minutes();
    runMonitor();

    Notification::assertSentOnDemandTimes(HostMonitorAlertNotification::class, 1);

    $this->travel(2)->minutes();
    runMonitor();

    Notification::assertSentOnDemandTimes(HostMonitorAlertNotification::class, 2);
});

it('says once when the problem has cleared and then goes quiet', function (): void {
    fakeHostSource(busySample(), busySample(), hostSample(), hostSample(), hostSample());

    for ($i = 0; $i < 5; $i++) {
        runMonitor();
    }

    Notification::assertSentOnDemandTimes(HostMonitorAlertNotification::class, 2);
    Notification::assertSentOnDemand(
        HostMonitorAlertNotification::class,
        static fn (HostMonitorAlertNotification $notification): bool => str_contains($notification->subject(), 'back to normal'),
    );
    expect(resolve(HostMonitorStore::class)->state('test-node')['notified_at'])->toBeNull();
});

it('can leave out the recovery message', function (): void {
    Config::set('capell.health.host.notifications.notify_on_recovery', false);
    fakeHostSource(busySample(), busySample(), hostSample(), hostSample());

    for ($i = 0; $i < 4; $i++) {
        runMonitor();
    }

    Notification::assertSentOnDemandTimes(HostMonitorAlertNotification::class, 1);
});

it('does not announce a problem below the configured minimum status', function (): void {
    Config::set('capell.health.host.notifications.minimum_status', 'critical');
    fakeHostSource(hostSample(['load5' => 1.8]));

    runMonitor();
    runMonitor();
    runMonitor();

    Notification::assertNothingSent();
});

it('does not email when no valid recipient is configured', function (): void {
    Config::set('capell.health.host.notifications.mail_to', ['not-an-address']);
    fakeHostSource(busySample());

    runMonitor();
    runMonitor();

    Notification::assertNothingSent();
});

it('posts to a webhook when that channel is configured', function (): void {
    Notification::swap(new ChannelManager(app()));
    Http::fake();
    Config::set('capell.health.host.notifications.channels', ['webhook']);
    Config::set('capell.health.host.notifications.webhook_url', 'https://hooks.example.com/ops');
    fakeHostSource(busySample());

    runMonitor();
    runMonitor();

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://hooks.example.com/ops'
        && str_contains((string) $request['text'], 'test-node')
        && str_contains((string) $request['text'], 'php artisan queue:work'));
});

it('ignores a webhook address that is not http or https', function (): void {
    Config::set('capell.health.host.notifications.channels', ['webhook']);
    Config::set('capell.health.host.notifications.webhook_url', 'file:///etc/passwd');
    fakeHostSource(busySample());

    runMonitor();
    runMonitor();

    Notification::assertNothingSent();
    expect(HostMonitorWebhookChannel::class)->toBeString();
});

it('keeps recording when the notification channel fails', function (): void {
    Notification::swap(new ChannelManager(app()));
    Http::fake(['*' => Http::response('nope', 500)]);
    Config::set('capell.health.host.notifications.channels', ['webhook']);
    Config::set('capell.health.host.notifications.webhook_url', 'https://hooks.example.com/ops');
    fakeHostSource(busySample());

    runMonitor();
    runMonitor();

    expect(resolve(HostMonitorStore::class)->samples('test-node'))->toHaveCount(2)
        ->and(resolve(HostMonitorStore::class)->state('test-node')['notified_at'])->toBeNull();
});

it('keeps the scheduler run going when the metrics source throws', function (): void {
    app()->instance(HostMetricsSource::class, new class implements HostMetricsSource
    {
        public function read(): HostSampleData
        {
            throw new RuntimeException('metrics unavailable');
        }
    });

    expect(resolve(RecordHostSampleAction::class)->handle())->toBeNull();
});

it('does not put the webhook address in the failure it reports', function (): void {
    Notification::swap(new ChannelManager(app()));
    Http::fake(static fn (): never => throw new ConnectionException('cURL error 6: Could not resolve host: hooks.example.com/ops-secret-token'));
    Config::set('capell.health.host.notifications.channels', ['webhook']);
    Config::set('capell.health.host.notifications.webhook_url', 'https://hooks.example.com/ops-secret-token');
    $reported = [];
    app()->instance(ExceptionHandler::class, new class($reported) implements ExceptionHandler
    {
        public function __construct(public array &$reported) {}

        public function report(Throwable $e): void
        {
            $this->reported[] = $e->getMessage();
        }

        public function shouldReport(Throwable $e): bool
        {
            return true;
        }

        public function render($request, Throwable $e): never
        {
            throw $e;
        }

        public function renderForConsole($output, Throwable $e): void {}
    });
    fakeHostSource(busySample());

    runMonitor();
    runMonitor();

    expect($reported)->not->toBe([])
        ->and(implode(' ', $reported))->not->toContain('ops-secret-token');
});
