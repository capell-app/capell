<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Contracts\AdminZoneContribution;
use Capell\Admin\Data\AdminZoneContextData;
use Capell\Admin\Data\AdminZoneContributionData;
use Capell\Admin\Enums\AdminZone;
use Capell\Core\Data\Extensions\ExtensionOrderDiagnosticData;
use Capell\Core\Exceptions\ExtensionContributionConflictException;
use Capell\Core\Support\Extensions\ExtensionOrderResolver;
use Capell\Core\Support\Extensions\ExtensionPosition;
use Closure;
use DateTimeInterface;
use Filament\Tables\Columns\Column;
use Laravel\SerializableClosure\SerializableClosure;
use LogicException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionObject;
use ReflectionReference;
use Throwable;
use UnitEnum;

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
            if ($existing === $contribution || $this->isEquivalent($existing, $contribution)) {
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
        throw_if(trim($key) === '', LogicException::class, 'Admin zone contribution keys must not be empty.');

        return $zone->value . ':' . $key;
    }

    private function isEquivalent(AdminZoneContribution $existing, AdminZoneContribution $contribution): bool
    {
        if (! $existing instanceof AdminZoneContributionData || ! $contribution instanceof AdminZoneContributionData) {
            return false;
        }

        $existingIdentity = $this->identity($existing);
        $contributionIdentity = $this->identity($contribution);

        return $existingIdentity !== null && $existingIdentity === $contributionIdentity;
    }

    private function identity(AdminZoneContributionData $contribution): ?string
    {
        try {
            $position = $contribution->position();
            $reflection = new ReflectionObject($contribution);
            $visibility = $this->privateProperty($reflection, $contribution, 'visibility');
            $visibilityIdentity = $visibility === null ? null : $this->callableIdentity($visibility);
            $resolverIdentity = $this->callableIdentity($this->privateProperty($reflection, $contribution, 'resolver'));

            if ($resolverIdentity === null || ($visibility !== null && $visibilityIdentity === null)) {
                return null;
            }

            return hash('sha256', serialize([
                'zone' => $contribution->zone()->value,
                'key' => $contribution->key(),
                'position' => [$position->kind, $position->priority, $position->anchor],
                'permission' => $this->privateProperty($reflection, $contribution, 'permission'),
                'visibility' => $visibilityIdentity,
                'resolver' => $resolverIdentity,
                'owner' => $contribution->owner(),
                'source' => $contribution->source(),
            ]));
        } catch (Throwable) {
            return null;
        }
    }

    private function privateProperty(ReflectionObject $reflection, AdminZoneContributionData $contribution, string $name): mixed
    {
        return $reflection->getProperty($name)->getValue($contribution);
    }

    private function callableIdentity(Closure $callable): ?string
    {
        try {
            $reflection = new ReflectionFunction($callable);

            if ($this->hasAmbiguousSourceLine($reflection)) {
                return null;
            }

            $serializableClosure = SerializableClosure::unsigned($callable);
            $serializable = new ReflectionObject($serializableClosure)->getProperty('serializable')->getValue($serializableClosure);

            if (! is_object($serializable) || ! method_exists($serializable, '__serialize')) {
                return null;
            }

            $representation = $serializable->__serialize();

            if (! is_array($representation) || ! array_key_exists('self', $representation)) {
                return null;
            }

            unset($representation['self']);

            $normalised = null;
            $activeObjects = [];

            if (! $this->normaliseIdentityValue($representation, $activeObjects, $normalised)) {
                return null;
            }

            return hash('sha256', serialize($normalised));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, true>  $activeObjects
     */
    private function normaliseIdentityValue(mixed $value, array &$activeObjects, mixed &$normalised): bool
    {
        if ($value === null || is_scalar($value)) {
            $normalised = [get_debug_type($value), $value];

            return true;
        }

        if (is_resource($value)) {
            return false;
        }

        if ($value instanceof UnitEnum) {
            $normalised = ['enum', $value::class, $value->name];

            return true;
        }

        if ($value instanceof DateTimeInterface) {
            $normalised = ['date', $value::class, $value->format('c.uP')];

            return true;
        }

        if ($value instanceof Closure) {
            $identity = $this->callableIdentity($value);

            if ($identity === null) {
                return false;
            }

            $normalised = ['closure', $identity];

            return true;
        }

        if (is_array($value)) {
            $items = [];

            foreach ($value as $key => $item) {
                if (ReflectionReference::fromArrayElement($value, $key) instanceof ReflectionReference) {
                    return false;
                }

                $itemValue = null;

                if (! $this->normaliseIdentityValue($item, $activeObjects, $itemValue)) {
                    return false;
                }

                $items[] = [$key, $itemValue];
            }

            $normalised = ['array', $items];

            return true;
        }

        if (! is_object($value)) {
            return false;
        }

        $objectId = spl_object_id($value);

        if (isset($activeObjects[$objectId])) {
            return false;
        }

        $reflection = new ReflectionClass($value);

        if (! $reflection->isUserDefined()) {
            return false;
        }

        $activeObjects[$objectId] = true;
        $properties = [];

        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $propertyKey = $property->getDeclaringClass()->getName() . '::' . $property->getName();

            if (! $property->isInitialized($value)) {
                $properties[$propertyKey] = ['uninitialised'];

                continue;
            }

            $propertyValue = null;

            if (! $this->normaliseIdentityValue($property->getValue($value), $activeObjects, $propertyValue)) {
                unset($activeObjects[$objectId]);

                return false;
            }

            $properties[$propertyKey] = $propertyValue;
        }

        unset($activeObjects[$objectId]);
        ksort($properties);
        $normalised = ['object', $reflection->getName(), $properties];

        return true;
    }

    private function hasAmbiguousSourceLine(ReflectionFunction $reflection): bool
    {
        $file = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        if (! is_string($file) || $file === '' || ! is_int($startLine) || ! is_int($endLine) || $startLine !== $endLine) {
            return false;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false || ! isset($lines[$startLine - 1])) {
            return true;
        }

        $closureTokens = array_filter(
            token_get_all('<?php ' . $lines[$startLine - 1]),
            static fn (array|string $token): bool => is_array($token) && in_array($token[0], [T_FUNCTION, T_FN], true),
        );

        return count($closureTokens) > 1;
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
