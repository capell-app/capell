<?php

declare(strict_types=1);

namespace Capell\Core\Support\Reporting;

use Capell\Core\Contracts\Reporting\Reporter;
use Capell\Core\Data\Reporting\RedactedSignalData;
use Capell\Core\Data\Reporting\TransportResultData;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Monolog\Handler\HandlerInterface;
use Override;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final readonly class ReportingTransportBoundary
{
    /** @var list<string> */
    private const array SAFE_LOG_DRIVERS = ['stack', 'single', 'daily', 'monthly', 'slack', 'syslog', 'errorlog'];

    private const int MAX_STACK_DEPTH = 16;

    public function __construct(private Container $container) {}

    public function report(RedactedSignalData $signal, mixed $binding): TransportResultData
    {
        try {
            throw_if(! is_string($binding) || $binding === '', RuntimeException::class, 'Reporting transport is not registered.');
            $reporter = $this->container->make($binding);
            throw_unless($reporter instanceof Reporter, RuntimeException::class, 'Reporting transport must implement Reporter.');
            $reporter->report($signal);

            return TransportResultData::delivered();
        } catch (Throwable) {
            return TransportResultData::unavailable();
        }
    }

    public function log(RedactedSignalData $signal, ?string $channel = null): TransportResultData
    {
        try {
            $channel = $this->assertSafeLogChannel($channel);
            $logs = $this->isolatedLogManager();
            $logs->channel($channel)->log($signal->severity->value, $signal->toJson());

            return TransportResultData::delivered();
        } catch (Throwable) {
            return TransportResultData::unavailable();
        }
    }

    public function email(RedactedSignalData $signal, ?string $operator, bool $escalated, ?string $cacheStore): TransportResultData
    {
        try {
            $receipt = $this->container->make(OperatorEmailChannel::class)->report($signal, $operator, $escalated, $cacheStore);

            return TransportResultData::receipt($receipt);
        } catch (Throwable) {
            return TransportResultData::unavailable();
        }
    }

    private function assertSafeLogChannel(?string $channel): string
    {
        $configuration = $this->container->make(Repository::class);
        $channel ??= $configuration->get('logging.default');
        throw_if(! is_string($channel) || $channel === '', RuntimeException::class, 'Reporting log channel is unavailable.');

        $seen = [];
        $this->assertSafeLogChannelConfiguration($configuration, $channel, $seen);

        return $channel;
    }

    /** @param array<string, true> $seen */
    private function assertSafeLogChannelConfiguration(Repository $configuration, string $channel, array &$seen, int $depth = 0): void
    {
        throw_if($depth > self::MAX_STACK_DEPTH || isset($seen[$channel]), RuntimeException::class, 'Reporting log stack is invalid.');
        $seen[$channel] = true;

        $settings = $configuration->get('logging.channels.' . $channel);
        throw_unless(is_array($settings), RuntimeException::class, 'Reporting log channel is not configured.');
        $driver = $settings['driver'] ?? null;
        throw_unless(is_string($driver) && in_array($driver, self::SAFE_LOG_DRIVERS, true), RuntimeException::class, 'Reporting log driver is not isolated.');
        throw_if(($settings['tap'] ?? []) !== [], RuntimeException::class, 'Reporting log taps are not isolated.');
        throw_if(isset($settings['formatter']) && $settings['formatter'] !== 'default', RuntimeException::class, 'Reporting log formatter is not isolated.');
        throw_if(($settings['ignore_exceptions'] ?? false) !== false, RuntimeException::class, 'Reporting log exceptions must not be ignored.');

        if ($driver === 'stack') {
            $members = $settings['channels'] ?? null;
            throw_unless(is_array($members) && array_is_list($members) && $members !== [], RuntimeException::class, 'Reporting log stack is invalid.');
            foreach ($members as $member) {
                throw_unless(is_string($member) && $member !== '', RuntimeException::class, 'Reporting log stack is invalid.');
                $this->assertSafeLogChannelConfiguration($configuration, $member, $seen, $depth + 1);
            }
        }

        unset($seen[$channel]);
    }

    private function isolatedLogManager(): LogManager
    {
        $configuration = $this->container->make(Repository::class);
        $logging = $configuration->get('logging');
        throw_unless(is_array($logging), RuntimeException::class, 'Reporting log configuration is unavailable.');

        $application = new class extends Application
        {
            public function __construct() {}

            public function runningUnitTests(): bool
            {
                return false;
            }
        };
        $application->instance('app', $application);
        $application->instance('config', new ConfigRepository([
            'app' => ['name' => 'capell-reporting'],
            'logging' => $logging,
        ]));

        return new class($application) extends LogManager
        {
            /** @param array<string, mixed>|null $config */
            #[Override]
            protected function get($name, ?array $config = null)
            {
                try {
                    if (isset($this->channels[$name])) {
                        return $this->channels[$name];
                    }

                    $logger = $this->resolve($name, $config);
                    throw_unless($logger instanceof LoggerInterface, RuntimeException::class, 'Reporting log driver is unavailable.');

                    return $this->channels[$name] = new Logger($logger);
                } catch (Throwable) {
                    throw new RuntimeException('Reporting log channel is unavailable.');
                }
            }

            /** @param array<string, mixed> $config */
            #[Override]
            protected function createCustomDriver(array $config): never
            {
                throw new RuntimeException('Reporting custom log factories are not isolated.');
            }

            /** @param array<string, mixed> $config */
            #[Override]
            protected function createMonologDriver(array $config): never
            {
                throw new RuntimeException('Reporting custom log handlers are not isolated.');
            }

            /** @param array<string, mixed> $config */
            #[Override]
            protected function prepareHandler(HandlerInterface $handler, array $config = [])
            {
                throw_if(isset($config['formatter']) && $config['formatter'] !== 'default', RuntimeException::class, 'Reporting log formatter is not isolated.');

                return parent::prepareHandler($handler, $config);
            }

            #[Override]
            protected function createEmergencyLogger(): never
            {
                throw new RuntimeException('Reporting log channel is unavailable.');
            }
        };
    }
}
