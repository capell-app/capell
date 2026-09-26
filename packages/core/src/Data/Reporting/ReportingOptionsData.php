<?php

declare(strict_types=1);

namespace Capell\Core\Data\Reporting;

use InvalidArgumentException;

final readonly class ReportingOptionsData
{
    /** @param array<string, mixed> $reporters */
    private function __construct(
        public bool $enabled = true,
        public string $transport = 'log',
        public int $cooldownSeconds = 300,
        public ?string $cacheStore = null,
        public ?string $logChannel = null,
        public array $reporters = [],
        public ?OperatorRoutingData $routing = null,
    ) {}

    public static function fromConfiguration(mixed $configuration, SignalData $signal): self
    {
        throw_if(! is_array($configuration), InvalidArgumentException::class, 'Reporting configuration is unavailable or invalid.');

        if (($configuration['enabled'] ?? true) === false) {
            return new self(enabled: false);
        }

        $signals = $configuration['signals'] ?? [];
        throw_unless(is_array($signals), InvalidArgumentException::class, 'Reporting policies must be arrays.');
        $specific = $signals[$signal->name] ?? [];
        throw_unless(is_array($specific), InvalidArgumentException::class, 'Reporting overrides must be arrays.');

        // Resolve disablement from the most specific policy before inspecting
        // inherited fields that cannot affect that decision.
        if (($specific['enabled'] ?? null) === false) {
            return new self(enabled: false);
        }

        $categories = $configuration['categories'] ?? [];
        throw_unless(is_array($categories), InvalidArgumentException::class, 'Reporting policies must be arrays.');
        $category = $categories[$signal->category->value] ?? [];
        throw_unless(is_array($category), InvalidArgumentException::class, 'Reporting overrides must be arrays.');
        if (! array_key_exists('enabled', $specific) && ($category['enabled'] ?? null) === false) {
            return new self(enabled: false);
        }

        $defaults = $configuration['defaults'] ?? [];
        throw_unless(is_array($defaults), InvalidArgumentException::class, 'Reporting policies must be arrays.');
        throw_unless(is_bool($configuration['enabled'] ?? true), InvalidArgumentException::class, 'Reporting enabled must be a boolean.');

        $policy = array_replace(['enabled' => true, 'transport' => 'log', 'cooldown_seconds' => 300], $defaults, $category, $specific);
        throw_unless(is_bool($policy['enabled']), InvalidArgumentException::class, 'Reporting enabled must be a boolean.');

        if (! $policy['enabled']) {
            return new self(enabled: false);
        }

        throw_if(! is_string($policy['transport']) || $policy['transport'] === '' || ! is_int($policy['cooldown_seconds']) || $policy['cooldown_seconds'] < 0 || $policy['cooldown_seconds'] > 86400, InvalidArgumentException::class, 'Reporting transport or cooldown is invalid.');

        $cacheStore = $configuration['cache_store'] ?? null;
        $logChannel = $configuration['log_channel'] ?? null;
        $reporters = $configuration['reporters'] ?? [];
        throw_if(($cacheStore !== null && (! is_string($cacheStore) || $cacheStore === '')) || ($logChannel !== null && (! is_string($logChannel) || $logChannel === '')) || ! is_array($reporters), InvalidArgumentException::class, 'Reporting services are invalid.');

        return new self($policy['enabled'], $policy['transport'], $policy['cooldown_seconds'], $cacheStore, $logChannel, $reporters, $policy['transport'] === 'operator' ? OperatorRoutingData::fromPolicy($policy) : null);
    }
}
