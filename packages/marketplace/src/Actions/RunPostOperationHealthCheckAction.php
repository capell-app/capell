<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Support\Process\ArtisanSubprocessRunner;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Marketplace\Data\MarketplaceHealthCheckResultData;
use Capell\Marketplace\Enums\MarketplaceHealthProbeOutcome;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Decide whether the site still works after a Composer-mutating operation.
 *
 * Deliberately knows nothing about installs: it takes a time budget and returns
 * a verdict, so the update and uninstall paths can ask the same question.
 *
 * The primary probe is a fresh `artisan capell:health-probe` process. WordPress
 * answers this question with an HTTP loopback request, which only proves that a
 * web server replied, and only on hosts that can reach their own public URL.
 * A new PHP process reading the autoload map and the package registry as they
 * are *now* is both stronger evidence and available everywhere.
 *
 * The HTTP smoke probe is kept as a secondary signal, and it can only ever
 * confirm — never condemn on a connection failure. A site that is not reachable
 * from inside itself is a normal deployment, not a broken install.
 */
final class RunPostOperationHealthCheckAction
{
    use AsFake;
    use AsObject;

    public const int DEFAULT_HTTP_TIMEOUT_SECONDS = 5;

    /**
     * The least time in which a boot probe result means anything.
     *
     * A fresh `artisan` boot has to load the autoload map, discover providers,
     * read config and open a database connection. On a warm developer machine
     * that is one to three seconds; on the shared hosting Capell targets — cold
     * filesystem caches, no opcache for a new process, an overloaded box — the
     * same boot routinely takes several times longer. Fifteen seconds is chosen
     * to sit above that slow-host case with room to spare, while still fitting
     * comfortably inside the 45-second health-check reserve alongside the HTTP
     * probe.
     *
     * Below it the probe is not run at all, because a ProcessTimedOutException
     * from a two-second budget is evidence about the clock, not about the site,
     * and condemning a working installation on that basis would invert the one
     * property this check exists to guarantee.
     */
    public const int MINIMUM_BOOT_PROBE_SECONDS = 15;

    /**
     * @param  int  $budgetSeconds  How long both probes together may take. The
     *                              caller owns this number: the health check spends the operation's
     *                              remaining job budget, it does not get one of its own.
     */
    public function handle(int $budgetSeconds): MarketplaceHealthCheckResultData
    {
        $httpTimeoutSeconds = $this->httpTimeoutSeconds();
        $bootProbeTimeoutSeconds = $budgetSeconds - $httpTimeoutSeconds;

        // Not enough time left for the probe to mean anything. Budget exhaustion
        // is a known reason the probe could not run, and it is distinguishable
        // from a probe that ran and found something — so it skips, loudly, and
        // the operation is reported as completed-but-unverified rather than
        // condemned and rolled back for being slow.
        if ($bootProbeTimeoutSeconds < self::MINIMUM_BOOT_PROBE_SECONDS) {
            return new MarketplaceHealthCheckResultData(
                bootProbe: MarketplaceHealthProbeOutcome::Skipped,
                httpProbe: MarketplaceHealthProbeOutcome::Skipped,
                skipReason: (string) __('capell-marketplace::marketplace.operations.health_check_skipped_no_budget', [
                    'seconds' => self::MINIMUM_BOOT_PROBE_SECONDS,
                ]),
            );
        }

        // An application with no artisan entry point cannot be probed by a
        // subprocess at all. That is a property of how this installation is laid
        // out, not evidence about the package change, so it skips exactly like
        // an unreachable APP_URL does — and the skip is on the timeline, so
        // nobody reads it as a confirmation.
        if (! $this->applicationHasArtisanEntryPoint()) {
            [$httpOnlyOutcome, $httpOnlyFailureReason] = $this->runHttpProbe($httpTimeoutSeconds);

            return new MarketplaceHealthCheckResultData(
                bootProbe: MarketplaceHealthProbeOutcome::Skipped,
                httpProbe: $httpOnlyOutcome,
                failureReason: $httpOnlyFailureReason,
                skipReason: (string) __('capell-marketplace::marketplace.operations.health_check_skipped_no_artisan'),
            );
        }

        $bootProbeLines = [];
        $exitCode = $this->runBootProbe($bootProbeTimeoutSeconds, $bootProbeLines);
        $bootProbeOutput = trim(implode("\n", $bootProbeLines));

        if ($exitCode !== 0) {
            return new MarketplaceHealthCheckResultData(
                bootProbe: MarketplaceHealthProbeOutcome::Failed,
                httpProbe: MarketplaceHealthProbeOutcome::Skipped,
                failureReason: (string) __('capell-marketplace::marketplace.operations.health_check_boot_failed', [
                    'code' => $exitCode,
                ]),
                bootProbeOutput: $bootProbeOutput,
            );
        }

        [$httpOutcome, $httpFailureReason] = $this->runHttpProbe($httpTimeoutSeconds);

        return new MarketplaceHealthCheckResultData(
            bootProbe: MarketplaceHealthProbeOutcome::Passed,
            httpProbe: $httpOutcome,
            failureReason: $httpFailureReason,
            bootProbeOutput: $bootProbeOutput,
        );
    }

    /**
     * A probe that could not even be started is a failed probe. The whole point
     * of this check is that the caller stops trusting the operation when it
     * cannot get positive evidence, and "the subprocess threw" is an absence of
     * evidence exactly like a non-zero exit is.
     *
     * @param  list<string>  $capturedLines
     */
    private function runBootProbe(int $timeoutSeconds, array &$capturedLines): int
    {
        $lines = [];

        try {
            $exitCode = new ArtisanSubprocessRunner(resolve(ProcessFactoryInterface::class))->run(
                ['capell:health-probe'],
                static function (string $line) use (&$lines): void {
                    $lines[] = $line;
                },
                $timeoutSeconds,
            );
        } catch (Throwable $throwable) {
            $lines[] = $throwable->getMessage();
            $capturedLines = $lines;

            return 1;
        }

        $capturedLines = $lines;

        return $exitCode;
    }

    /**
     * @return array{0: MarketplaceHealthProbeOutcome, 1: string|null}
     */
    private function runHttpProbe(int $timeoutSeconds): array
    {
        if (config('capell-marketplace.marketplace.health_check.http_probe', true) !== true) {
            return [MarketplaceHealthProbeOutcome::Skipped, null];
        }

        $applicationUrl = config('app.url');

        if (! is_string($applicationUrl) || ! Str::startsWith($applicationUrl, ['http://', 'https://'])) {
            return [MarketplaceHealthProbeOutcome::Skipped, null];
        }

        try {
            $response = Http::timeout($timeoutSeconds)
                ->withoutRedirecting()
                ->get($applicationUrl);
        } catch (ConnectionException) {
            // Not self-reachable. Says nothing about the install.
            return [MarketplaceHealthProbeOutcome::Skipped, null];
        } catch (Throwable) {
            return [MarketplaceHealthProbeOutcome::Skipped, null];
        }

        // Only a server error condemns. A redirect, an authentication wall or a
        // 404 on a headless installation are all things a correctly working
        // Capell site legitimately answers with, and failing an install over one
        // of them would roll back a good install for a routing preference.
        if ($response->serverError()) {
            return [
                MarketplaceHealthProbeOutcome::Failed,
                (string) __('capell-marketplace::marketplace.operations.health_check_http_failed', [
                    'status' => $response->status(),
                ]),
            ];
        }

        return [MarketplaceHealthProbeOutcome::Passed, null];
    }

    private function applicationHasArtisanEntryPoint(): bool
    {
        return resolve(Filesystem::class)->exists(base_path('artisan'));
    }

    private function httpTimeoutSeconds(): int
    {
        $configured = config(
            'capell-marketplace.marketplace.health_check.http_timeout_seconds',
            self::DEFAULT_HTTP_TIMEOUT_SECONDS,
        );

        return is_numeric($configured) && (int) $configured > 0
            ? (int) $configured
            : self::DEFAULT_HTTP_TIMEOUT_SECONDS;
    }
}
