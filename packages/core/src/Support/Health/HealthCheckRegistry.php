<?php

declare(strict_types=1);

namespace Capell\Core\Support\Health;

use Capell\Core\Contracts\Health\HealthCheck;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use LogicException;

final class HealthCheckRegistry
{
    /** @var array<string, HealthCheck> */
    private array $checks = [];

    private bool $discovered = false;

    public function __construct(private readonly Container $container) {}

    public function register(HealthCheck $check): self
    {
        $this->validate($check);
        throw_if(isset($this->checks[$check->id()]), InvalidArgumentException::class, sprintf('Duplicate health check ID [%s].', $check->id()));
        $this->checks[$check->id()] = $check;

        return $this;
    }

    /** @return list<HealthCheck> */
    public function checks(): array
    {
        $this->discover();
        ksort($this->checks);

        return array_values($this->checks);
    }

    public function find(string $id): ?HealthCheck
    {
        $this->discover();

        return $this->checks[$id] ?? null;
    }

    private function discover(): void
    {
        if ($this->discovered) {
            return;
        }

        $tagged = iterator_to_array($this->container->tagged(HealthCheck::TAG));
        usort($tagged, static fn (mixed $left, mixed $right): int => get_debug_type($left) <=> get_debug_type($right));
        $discovered = [];
        foreach ($tagged as $check) {
            throw_unless($check instanceof HealthCheck, LogicException::class, 'Tagged health checks must implement the health check contract.');
            $this->validate($check);
            throw_if(isset($this->checks[$check->id()]) || isset($discovered[$check->id()]), InvalidArgumentException::class, sprintf('Duplicate health check ID [%s].', $check->id()));
            $discovered[$check->id()] = $check;
        }

        $this->checks = [...$this->checks, ...$discovered];
        $this->discovered = true;
    }

    private function validate(HealthCheck $check): void
    {
        throw_if(preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/', $check->id()) !== 1, InvalidArgumentException::class, 'Health check IDs must be stable lowercase identifiers.');
        throw_if(preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/', $check->category()) !== 1, InvalidArgumentException::class, 'Health check categories must be stable lowercase identifiers.');
        throw_if($check->timeoutSeconds() < 1, InvalidArgumentException::class, 'Health check timeouts must be positive.');
    }
}
