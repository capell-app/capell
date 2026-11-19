<?php

declare(strict_types=1);

namespace Capell\Core\Support\Http;

use Capell\Core\Contracts\Security\ResolvesHostAddressesWithin;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\Log;
use JsonException;
use Override;
use ReflectionClass;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Resolves a host in a child PHP process so the lookup can be abandoned at a
 * deadline.
 *
 * `gethostbynamel()` and `dns_get_record()` take no timeout and cannot be
 * interrupted from PHP, so a stalled DNS server would otherwise hold a guarded
 * request past every timeout it sets. The child runs DnsHostAddressResolver, so
 * the answer is exactly what the system resolver gives, and is killed when the
 * deadline passes; the host then counts as unresolved and the request is refused.
 *
 * Where a child process cannot be started (proc_open disabled, or no PHP CLI
 * binary to run) the lookup runs in-process as before.
 */
final readonly class ProcessHostAddressResolver implements ResolvesHostAddressesWithin
{
    /** The deadline for callers that do not pass one. */
    public const float DEFAULT_TIMEOUT_SECONDS = 5.0;

    private string $script;

    public function __construct(?string $script = null, private bool $childProcessesAllowed = true)
    {
        $this->script = $script ?? dirname(__DIR__, 3) . '/resources/runtime/resolve-host-addresses.php';
    }

    /** @return list<string> */
    #[Override]
    public function resolve(string $host): array
    {
        return $this->resolveWithin($host, self::DEFAULT_TIMEOUT_SECONDS);
    }

    /** @return list<string> */
    #[Override]
    public function resolveWithin(string $host, float $seconds): array
    {
        $php = $this->phpBinary();
        $autoload = $this->autoloadPath();

        if ($php === null || $autoload === null) {
            $this->warnUnbounded();

            return (new DnsHostAddressResolver)->resolve($host);
        }

        $process = new Process([$php, '-d', 'display_errors=0', $this->script, $autoload, $host]);
        $process->setTimeout(max(0.1, $seconds));

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            // Process::run() has already stopped the child.
            return [];
        }

        if (! $process->isSuccessful()) {
            return [];
        }

        try {
            $decoded = json_decode($process->getOutput(), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $addresses = [];

        foreach ($decoded as $address) {
            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Logged once per process: without a child process the lookup cannot be
     * abandoned, so operators need to know the deadline is not in force.
     */
    private function warnUnbounded(): void
    {
        static $warned = false;

        if ($warned) {
            return;
        }

        $warned = true;
        Log::warning('capell-core: guarded DNS lookups run without a deadline because a PHP child process cannot be started (proc_open is disabled or no PHP CLI binary was found).');
    }

    private function phpBinary(): ?string
    {
        if (! $this->childProcessesAllowed) {
            return null;
        }

        if (! function_exists('proc_open')) {
            return null;
        }

        $php = (new PhpExecutableFinder)->find(false);

        return is_string($php) && $php !== '' ? $php : null;
    }

    private function autoloadPath(): ?string
    {
        $loader = new ReflectionClass(ClassLoader::class)->getFileName();

        if (! is_string($loader)) {
            return null;
        }

        $autoload = dirname($loader, 2) . '/autoload.php';

        return is_file($autoload) ? $autoload : null;
    }
}
