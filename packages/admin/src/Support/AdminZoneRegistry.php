<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Contracts\AdminZoneContribution;
use Capell\Admin\Data\AdminZoneContextData;
use Capell\Admin\Enums\AdminZone;
use Capell\Core\Data\Extensions\ExtensionOrderDiagnosticData;
use Capell\Core\Exceptions\ExtensionContributionConflictException;
use Capell\Core\Support\Extensions\ExtensionOrderResolver;
use Capell\Core\Support\Extensions\ExtensionPosition;
use Filament\Tables\Columns\Column;
use LogicException;

final class AdminZoneRegistry
{
    /** @var array<string, AdminZoneContribution> */
    private array $contributions = [];

    private bool $frozen = false;

    public function __construct(private readonly ?ExtensionOrderResolver $orderResolver = null) {}

    public function register(AdminZoneContribution $contribution): void
    {
        if ($this->frozen) {
            throw ExtensionContributionConflictException::frozen($contribution->owner(), $contribution->source());
        }

        $key = $this->indexKey($contribution->zone(), $contribution->key());
        $existing = $this->contributions[$key] ?? null;

        if ($existing instanceof AdminZoneContribution) {
            if ($existing === $contribution) {
                return;
            }

            throw ExtensionContributionConflictException::duplicate(
                $contribution->key(),
                $existing->owner(),
                $existing->source(),
                $contribution->owner(),
                $contribution->source(),
            );
        }

        $this->contributions[$key] = $contribution;
    }

    public function replace(AdminZoneContribution $contribution): void
    {
        if ($this->frozen) {
            throw ExtensionContributionConflictException::frozen($contribution->owner(), $contribution->source());
        }

        $key = $this->indexKey($contribution->zone(), $contribution->key());

        if (! isset($this->contributions[$key])) {
            throw new LogicException(sprintf('Cannot replace missing Admin zone key [%s].', $contribution->key()));
        }

        $this->contributions[$key] = $contribution;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * @return list<mixed>
     */
    public function resolve(AdminZone $zone, AdminZoneContextData $context): array
    {
        $resolved = [];

        foreach ($this->ordered($zone) as $contribution) {
            if (! $contribution->isVisible($context)) {
                continue;
            }

            foreach ($contribution->resolve($context) as $value) {
                $this->assertValue($zone, $value, $contribution);
                $resolved[] = $value;
            }
        }

        return $resolved;
    }

    /** @return list<AdminZoneContribution> */
    public function contributions(AdminZone $zone): array
    {
        return $this->ordered($zone);
    }

    /** @return list<ExtensionOrderDiagnosticData> */
    public function orderingDiagnostics(AdminZone $zone): array
    {
        $resolver = $this->orderResolver ?? new ExtensionOrderResolver;
        $this->ordered($zone, $resolver);

        return $resolver->diagnostics();
    }

    public function clear(): void
    {
        $this->contributions = [];
        $this->frozen = false;
    }

    /** @return list<AdminZoneContribution> */
    private function ordered(AdminZone $zone, ?ExtensionOrderResolver $resolver = null): array
    {
        $items = array_values(array_filter(
            $this->contributions,
            static fn (AdminZoneContribution $contribution): bool => $contribution->zone()->value === $zone->value,
        ));
        $resolver ??= $this->orderResolver ?? new ExtensionOrderResolver;

        return $resolver->resolve(
            $items,
            static fn (AdminZoneContribution $contribution, int $index): string => $contribution->key(),
            static fn (AdminZoneContribution $contribution): ExtensionPosition => $contribution->position(),
        );
    }

    private function indexKey(AdminZone $zone, string $key): string
    {
        if (trim($key) === '') {
            throw new LogicException('Admin zone contribution keys must not be empty.');
        }

        return $zone->value . ':' . $key;
    }

    private function assertValue(AdminZone $zone, mixed $value, AdminZoneContribution $contribution): void
    {
        $valid = match ($zone) {
            AdminZone::PageListTableColumns => $value instanceof Column,
        };

        if (! $valid) {
            throw new LogicException(sprintf(
                'Admin zone [%s] contribution [%s] from [%s] returned [%s].',
                $zone->value,
                $contribution->key(),
                $contribution->source(),
                get_debug_type($value),
            ));
        }
    }
}
