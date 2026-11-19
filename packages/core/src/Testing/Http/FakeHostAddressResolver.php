<?php

declare(strict_types=1);

namespace Capell\Core\Testing\Http;

use Capell\Core\Contracts\Security\ResolvesHostAddresses;
use Override;

/**
 * Answers guarded outbound requests' DNS lookups from a fixed table, so tests that
 * fake the HTTP client neither touch the network nor start the resolver's child
 * process. The `*` entry answers every host the table does not name.
 */
final readonly class FakeHostAddressResolver implements ResolvesHostAddresses
{
    /** A documentation-range public address that every test host may share. */
    public const string PUBLIC_ADDRESS = '93.184.216.34';

    /** @param  array<string, list<string>>  $answers  host => addresses */
    public function __construct(private array $answers = ['*' => [self::PUBLIC_ADDRESS]]) {}

    /** @param  array<string, list<string>>  $answers  host => addresses */
    public static function bind(array $answers = ['*' => [self::PUBLIC_ADDRESS]]): self
    {
        $resolver = new self($answers);
        app()->instance(ResolvesHostAddresses::class, $resolver);

        return $resolver;
    }

    /** @return list<string> */
    #[Override]
    public function resolve(string $host): array
    {
        return $this->answers[strtolower($host)] ?? $this->answers['*'] ?? [];
    }
}
