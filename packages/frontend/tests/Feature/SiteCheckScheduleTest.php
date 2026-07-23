<?php

declare(strict_types=1);

use Capell\Frontend\Providers\FrontendServiceProvider;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command;

function frontendScheduledEventFor(string $commandName): ?Event
{
    foreach (resolve(Schedule::class)->events() as $event) {
        if (Event::normalizeCommand((string) $event->command) === 'php artisan ' . $commandName) {
            return $event;
        }
    }

    return null;
}

function registerFrontendSiteCheckSchedule(
    mixed $frequency,
    bool $runningInConsole = true,
    bool $commandAvailable = true,
): ?Event {
    config()->set('capell-frontend.schedule_page_cleaner', $frequency);

    $schedule = new Schedule(app());
    app()->instance(Schedule::class, $schedule);

    if ($commandAvailable) {
        resolve(ConsoleKernel::class)->registerCommand(new Command('capell:frontend-site-check'));
    }

    $application = app();
    $runningInConsoleProperty = new ReflectionProperty(Application::class, 'isRunningInConsole');
    $previousRunningInConsole = $runningInConsoleProperty->getValue($application);

    try {
        $runningInConsoleProperty->setValue($application, $runningInConsole);

        $provider = $application->getProvider(FrontendServiceProvider::class);

        expect($provider)->toBeInstanceOf(FrontendServiceProvider::class);

        $registerSchedule = new ReflectionMethod(FrontendServiceProvider::class, 'registerSiteCheckSchedule');
        $registerSchedule->invoke($provider);

        return frontendScheduledEventFor('capell:frontend-site-check');
    } finally {
        $runningInConsoleProperty->setValue($application, $previousRunningInConsole);
    }
}

it('registers the frontend site check with its configured default frequency', function (): void {
    $event = registerFrontendSiteCheckSchedule('daily');

    expect($event)->not->toBeNull()
        ->and(Event::normalizeCommand((string) $event?->command))->toBe('php artisan capell:frontend-site-check')
        ->and($event?->getExpression())->toBe('0 0 * * *');
});

it('does not schedule the optional frontend site check when its command is unavailable', function (): void {
    expect(registerFrontendSiteCheckSchedule('daily', commandAvailable: false))->toBeNull();
});

it('registers every supported frontend site check frequency', function (string $frequency, string $expression): void {
    $event = registerFrontendSiteCheckSchedule($frequency);

    expect($event)->not->toBeNull()
        ->and(Event::normalizeCommand((string) $event?->command))->toBe('php artisan capell:frontend-site-check')
        ->and($event?->getExpression())->toBe($expression)
        ->and($event?->withoutOverlapping)->toBeFalse()
        ->and($event?->onOneServer)->toBeFalse();
})->with([
    'every minute' => ['everyMinute', '* * * * *'],
    'every two minutes' => ['everyTwoMinutes', '*/2 * * * *'],
    'every three minutes' => ['everyThreeMinutes', '*/3 * * * *'],
    'every four minutes' => ['everyFourMinutes', '*/4 * * * *'],
    'every five minutes' => ['everyFiveMinutes', '*/5 * * * *'],
    'every ten minutes' => ['everyTenMinutes', '*/10 * * * *'],
    'every fifteen minutes' => ['everyFifteenMinutes', '*/15 * * * *'],
    'every thirty minutes' => ['everyThirtyMinutes', '*/30 * * * *'],
    'hourly' => ['hourly', '0 * * * *'],
    'every two hours' => ['everyTwoHours', '0 */2 * * *'],
    'every three hours' => ['everyThreeHours', '0 */3 * * *'],
    'every four hours' => ['everyFourHours', '0 */4 * * *'],
    'every six hours' => ['everySixHours', '0 */6 * * *'],
    'daily' => ['daily', '0 0 * * *'],
    'twice daily' => ['twiceDaily', '0 1,13 * * *'],
    'weekly' => ['weekly', '0 0 * * 0'],
    'monthly' => ['monthly', '0 0 1 * *'],
    'quarterly' => ['quarterly', '0 0 1 1-12/3 *'],
    'yearly' => ['yearly', '0 0 1 1 *'],
]);

it('does not register an invalid frontend site check frequency and logs the configuration error', function (): void {
    $log = Log::spy();

    expect(registerFrontendSiteCheckSchedule('fortnightly'))->toBeNull();

    $log->shouldHaveReceived('warning')
        ->once()
        ->with('Invalid schedule frequency: fortnightly');
});

it('silently ignores empty and non-string frontend site check frequencies', function (mixed $frequency): void {
    $log = Log::spy();

    expect(registerFrontendSiteCheckSchedule($frequency))->toBeNull();

    $log->shouldNotHaveReceived('warning');
})->with([
    'empty' => '',
    'integer' => 1,
]);

it('does not resolve or register the frontend site check schedule outside the console', function (): void {
    expect(registerFrontendSiteCheckSchedule('daily', runningInConsole: false))->toBeNull();
});
