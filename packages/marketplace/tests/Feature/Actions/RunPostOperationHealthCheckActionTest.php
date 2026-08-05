<?php

declare(strict_types=1);

use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Marketplace\Actions\RunPostOperationHealthCheckAction;
use Capell\Marketplace\Enums\MarketplaceHealthProbeOutcome;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * Give the action an artisan entry point to find, so the boot probe is actually
 * exercised rather than skipped. Testbench's skeleton has no artisan file.
 */
function withArtisanEntryPoint(callable $body): void
{
    $artisanPath = base_path('artisan');
    $existed = File::exists($artisanPath);

    if (! $existed) {
        File::put($artisanPath, "<?php\n");
    }

    try {
        $body();
    } finally {
        if (! $existed) {
            File::delete($artisanPath);
        }
    }
}

function fakeHealthProbeProcess(int $exitCode, string $output = ''): void
{
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setTimeout')->andReturnSelf();
    $process->shouldReceive('run')->andReturnUsing(function (?callable $onOutput) use ($output): int {
        if ($onOutput !== null && $output !== '') {
            $onOutput(Process::OUT, $output);
        }

        return 0;
    });
    $process->shouldReceive('getExitCode')->andReturn($exitCode);

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($process);

    app()->instance(ProcessFactoryInterface::class, $factory);
}

beforeEach(function (): void {
    config()->set('app.url', 'https://capell.test');
    config()->set('capell-marketplace.marketplace.health_check.http_probe', true);
});

it('passes when a fresh process boots and the site answers', function (): void {
    Http::fake(['https://capell.test' => Http::response('ok', 200)]);

    withArtisanEntryPoint(function (): void {
        fakeHealthProbeProcess(0, 'capell:health-probe ok packages=4');

        $result = RunPostOperationHealthCheckAction::run(45);

        expect($result->passed())->toBeTrue()
            ->and($result->bootProbe)->toBe(MarketplaceHealthProbeOutcome::Passed)
            ->and($result->httpProbe)->toBe(MarketplaceHealthProbeOutcome::Passed)
            ->and($result->failureReason)->toBeNull()
            ->and($result->bootProbeOutput)->toContain('capell:health-probe ok packages=4');
    });
});

it('fails when a fresh process cannot boot the application', function (): void {
    withArtisanEntryPoint(function (): void {
        fakeHealthProbeProcess(255);

        $result = RunPostOperationHealthCheckAction::run(45);

        expect($result->passed())->toBeFalse()
            ->and($result->bootProbe)->toBe(MarketplaceHealthProbeOutcome::Failed)
            // The site is not asked anything once the boot probe has condemned
            // it: the answer would add nothing and costs a request.
            ->and($result->httpProbe)->toBe(MarketplaceHealthProbeOutcome::Skipped)
            ->and($result->failureReason)->toContain('255');
    });
});

it('treats a boot probe that could not be started as a failure', function (): void {
    // Absence of evidence is not evidence the site is fine. A probe that threw
    // proves nothing, and this check exists to withhold confidence.
    withArtisanEntryPoint(function (): void {
        $factory = Mockery::mock(ProcessFactoryInterface::class);
        $factory->shouldReceive('make')->andThrow(new RuntimeException('php binary is not executable'));
        app()->instance(ProcessFactoryInterface::class, $factory);

        $result = RunPostOperationHealthCheckAction::run(45);

        expect($result->passed())->toBeFalse()
            ->and($result->bootProbe)->toBe(MarketplaceHealthProbeOutcome::Failed)
            ->and($result->bootProbeOutput)->toContain('php binary is not executable');
    });
});

it('skips the boot probe rather than failing when there is no artisan entry point', function (): void {
    Http::fake(['https://capell.test' => Http::response('ok', 200)]);

    // An application laid out without an artisan entry point cannot be probed by
    // a subprocess at all. That is a property of the layout, not evidence about
    // the package change, so it must not condemn the install.
    app()->instance(Filesystem::class, new class extends Filesystem
    {
        #[Override]
        public function exists($path): bool
        {
            return ! str_ends_with((string) $path, DIRECTORY_SEPARATOR . 'artisan') && parent::exists($path);
        }
    });

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->never();
    app()->instance(ProcessFactoryInterface::class, $factory);

    $result = RunPostOperationHealthCheckAction::run(45);

    expect($result->bootProbe)->toBe(MarketplaceHealthProbeOutcome::Skipped)
        ->and($result->passed())->toBeTrue();
});

it('auto-skips the http probe when the site is not reachable from inside itself', function (): void {
    // Extremely common behind a load balancer or in a container. A site that
    // cannot reach its own public URL is not a broken site.
    Http::fake(fn (): never => throw new ConnectionException('Could not resolve host'));

    withArtisanEntryPoint(function (): void {
        fakeHealthProbeProcess(0);

        $result = RunPostOperationHealthCheckAction::run(45);

        expect($result->passed())->toBeTrue()
            ->and($result->bootProbe)->toBe(MarketplaceHealthProbeOutcome::Passed)
            ->and($result->httpProbe)->toBe(MarketplaceHealthProbeOutcome::Skipped);
    });
});

it('does not condemn a site over a redirect or an authentication wall', function (int $tolerableStatus): void {
    // A headless or login-gated Capell site legitimately answers its own
    // homepage with these. Rolling an install back over a routing preference
    // would be worse than not checking at all.
    Http::fake(['https://capell.test' => Http::response('', $tolerableStatus)]);

    withArtisanEntryPoint(function (): void {
        fakeHealthProbeProcess(0);

        expect(RunPostOperationHealthCheckAction::run(45)->httpProbe)
            ->toBe(MarketplaceHealthProbeOutcome::Passed);
    });
})->with([302, 401, 404]);

it('fails on a server error from the site itself', function (): void {
    Http::fake(['https://capell.test' => Http::response('', 500)]);

    withArtisanEntryPoint(function (): void {
        fakeHealthProbeProcess(0);
        $result = RunPostOperationHealthCheckAction::run(45);

        expect($result->httpProbe)->toBe(MarketplaceHealthProbeOutcome::Failed)
            ->and($result->passed())->toBeFalse()
            ->and($result->failureReason)->toContain('500');
    });
});

it('skips the http probe when the operator turned it off', function (): void {
    config()->set('capell-marketplace.marketplace.health_check.http_probe', false);
    Http::fake();

    withArtisanEntryPoint(function (): void {
        fakeHealthProbeProcess(0);

        $result = RunPostOperationHealthCheckAction::run(45);

        expect($result->httpProbe)->toBe(MarketplaceHealthProbeOutcome::Skipped)
            ->and($result->passed())->toBeTrue();
    });

    Http::assertNothingSent();
});

it('skips the http probe when APP_URL is not an http url', function (): void {
    config()->set('app.url', 'capell.test');
    Http::fake();

    withArtisanEntryPoint(function (): void {
        fakeHealthProbeProcess(0);

        expect(RunPostOperationHealthCheckAction::run(45)->httpProbe)
            ->toBe(MarketplaceHealthProbeOutcome::Skipped);
    });

    Http::assertNothingSent();
});

it('leaves the boot probe the budget the http probe does not need', function (): void {
    $capturedTimeout = null;

    withArtisanEntryPoint(function () use (&$capturedTimeout): void {
        config()->set('capell-marketplace.marketplace.health_check.http_timeout_seconds', 5);

        $process = Mockery::mock(Process::class);
        $process->shouldReceive('setTimeout')->andReturnUsing(function (?float $timeout) use (&$capturedTimeout, $process): Process {
            $capturedTimeout = $timeout;

            return $process;
        });
        $process->shouldReceive('run')->andReturn(0);
        $process->shouldReceive('getExitCode')->andReturn(0);

        $factory = Mockery::mock(ProcessFactoryInterface::class);
        $factory->shouldReceive('make')->andReturn($process);
        app()->instance(ProcessFactoryInterface::class, $factory);

        Http::fake(['https://capell.test' => Http::response('ok', 200)]);

        RunPostOperationHealthCheckAction::run(45);
    });

    expect($capturedTimeout)->toEqual(40);
});
