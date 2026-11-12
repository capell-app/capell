<?php

declare(strict_types=1);

namespace Capell\Core\Support\Http;

use InvalidArgumentException;

/**
 * What a guarded outbound request may do: which schemes and ports it may reach,
 * how many redirects it may follow, how much it may download and how long it may
 * take. The defaults are the strictest useful setting; callers loosen them
 * explicitly.
 */
final readonly class OutboundHttpPolicy
{
    public const int DEFAULT_MAX_BYTES = 5 * 1024 * 1024;

    /**
     * @param  list<string>  $schemes
     * @param  list<int>  $ports
     */
    public function __construct(
        public array $schemes = ['https', 'http'],
        public array $ports = [80, 443],
        public int $maxRedirects = 0,
        public int $maxBytes = self::DEFAULT_MAX_BYTES,
        public int $connectTimeoutSeconds = 5,
        public int $timeoutSeconds = 15,
    ) {
        throw_if($schemes === [] || array_diff($schemes, ['http', 'https']) !== [], InvalidArgumentException::class, 'Outbound requests may use only the http and https schemes.');
        throw_if($ports === [], InvalidArgumentException::class, 'An outbound request policy must allow at least one port.');
        throw_if($maxRedirects < 0 || $maxRedirects > 10, InvalidArgumentException::class, 'An outbound request may follow between 0 and 10 redirects.');
        throw_if($maxBytes < 1, InvalidArgumentException::class, 'An outbound request must have a positive download limit.');
        throw_if($connectTimeoutSeconds < 1 || $timeoutSeconds < 1, InvalidArgumentException::class, 'An outbound request must have positive timeouts.');
    }

    public function allowsScheme(string $scheme): bool
    {
        return in_array(strtolower($scheme), $this->schemes, true);
    }

    public function allowsPort(int $port): bool
    {
        return in_array($port, $this->ports, true);
    }
}
