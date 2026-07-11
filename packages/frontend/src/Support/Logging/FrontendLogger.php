<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Logging;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class FrontendLogger
{
    private readonly LoggerInterface $logger;

    private readonly bool $debugEnabled;

    private string $requestId = '';

    public function __construct()
    {
        $this->logger = $this->resolveLogger();

        $this->debugEnabled = (bool) Config::get('capell-frontend.debug_log');
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        if ($this->debugEnabled) {
            $this->logger->debug($message, $this->context($context));
        }
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $this->context($context));
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $this->context($context));
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        if ($this->debugEnabled) {
            $this->logger->info($message, $this->context($context));
        }
    }

    /** @param array<string, mixed> $context */
    public function notice(string $message, array $context = []): void
    {
        if ($this->debugEnabled) {
            $this->logger->notice($message, $this->context($context));
        }
    }

    /** @param array<string, mixed> $context */
    public function log(string $message, array $context = []): void
    {
        $this->info($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function context(array $context): array
    {
        if ($this->requestId !== '') {
            $context['request_id'] = $this->requestId;
        }

        return $this->appendCallerToContext($context);
    }

    /**
     * Append caller location to the given context by inspecting the backtrace.
     * Skips frames inside this logger class so the real caller (first frame
     * outside this class) is recorded.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function appendCallerToContext(array $context): array
    {
        if (array_key_exists('caller', $context)) {
            return $context;
        }

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        foreach ($backtrace as $frame) {
            if (isset($frame['class']) && $frame['class'] === self::class) {
                // skip frames that originate from this logger
                continue;
            }

            if (! isset($frame['file'])) {
                continue;
            }

            $context['caller'] = [
                'file' => $frame['file'],
                'line' => $frame['line'] ?? 0,
                'class' => $frame['class'] ?? null,
                'function' => $frame['function'],
            ];

            break;
        }

        return $context;
    }

    private function resolveLogger(): LoggerInterface
    {
        return is_array(Config::get('logging.channels.capell'))
            ? Log::channel('capell')
            : Log::getLogger();
    }
}
