<?php

declare(strict_types=1);

namespace Capell\Core\Support\Reporting;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Log\LogManager;
use ReflectionClass;
use ReflectionFunction;
use ReflectionObject;
use ReflectionProperty;
use RuntimeException;
use SplObjectStorage;
use Throwable;

final readonly class ReportingLoggerBoundary
{
    private const int MAX_INSPECTED_VALUES = 8192;

    private const int MAX_INSPECTION_DEPTH = 12;

    public function __construct(private LogManager $manager, private Container $container) {}

    public function guard(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable) {
            throw $this->unavailable();
        }
    }

    public function guardCallback(mixed $callback, Closure $operation): mixed
    {
        return $this->guard(function () use ($callback, $operation): mixed {
            $this->assertIsolated($callback);

            return $operation();
        });
    }

    public function guardContainer(): void
    {
        $this->container->beforeResolving(function (string $abstract, array $parameters, Container $container): void {
            $binding = $container->getBindings()[$container->getAlias($abstract)] ?? null;

            if (is_array($binding) && ($binding['concrete'] ?? null) instanceof Closure) {
                $this->assertIsolated($binding['concrete']);
            }
        });
        $this->container->resolving(function (mixed $resolved): void {
            $this->assertIsolated($resolved);
        });
    }

    private function assertIsolated(mixed $value): void
    {
        $seen = new SplObjectStorage;
        $remaining = self::MAX_INSPECTED_VALUES;

        throw_unless($this->isIsolated($value, $seen, $remaining), RuntimeException::class, 'Reporting log transport is unavailable.');
    }

    /** @param SplObjectStorage<object, null> $seen */
    private function isIsolated(mixed $value, SplObjectStorage $seen, int &$remaining, int $depth = 0): bool
    {
        if ($remaining-- <= 0 || $depth > self::MAX_INSPECTION_DEPTH) {
            return false;
        }

        if ($value instanceof LogManager) {
            return $value === $this->manager;
        }

        if ($value instanceof Container) {
            return $value === $this->container;
        }

        if (is_array($value)) {
            return array_all($value, fn ($item): bool => $this->isIsolated($item, $seen, $remaining, $depth + 1));
        }

        if (! is_object($value)) {
            return true;
        }

        if ($seen->contains($value)) {
            return true;
        }

        $seen->attach($value);

        if ($value instanceof Closure) {
            $reflection = new ReflectionFunction($value);
            foreach ($reflection->getStaticVariables() as $captured) {
                if (! $this->isIsolated($captured, $seen, $remaining, $depth + 1)) {
                    return false;
                }
            }

            $bound = $reflection->getClosureThis();
            if ($bound === null) {
                return true;
            }

            if (! $this->usesBoundObject($value)) {
                return true;
            }

            return $this->isIsolated($bound, $seen, $remaining, $depth + 1);
        }

        try {
            $reflection = new ReflectionObject($value);
            do {
                foreach ($reflection->getProperties() as $property) {
                    if (! $this->isReadableInstanceProperty($property, $reflection, $value)) {
                        continue;
                    }

                    if (! $this->isIsolated($property->getValue($value), $seen, $remaining, $depth + 1)) {
                        return false;
                    }
                }

                $reflection = $reflection->getParentClass();
            } while ($reflection !== false);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /** @param ReflectionClass<object> $class */
    private function isReadableInstanceProperty(ReflectionProperty $property, ReflectionClass $class, object $value): bool
    {
        return $property->getDeclaringClass()->getName() === $class->getName()
            && ! $property->isStatic()
            && $property->isInitialized($value);
    }

    private function usesBoundObject(Closure $closure): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return ! $closure->bindTo(null) instanceof Closure;
        } finally {
            restore_error_handler();
        }
    }

    private function unavailable(): RuntimeException
    {
        return new RuntimeException('Reporting log transport is unavailable.');
    }
}
