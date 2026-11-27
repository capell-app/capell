<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Settings;

use Capell\Core\Support\Settings\RegisteredSettingsClasses;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use ReflectionClass;
use ReflectionProperty;
use Spatie\LaravelSettings\Settings;

/**
 * Reads registered Capell settings as plain values. Encrypted properties are never returned;
 * they are reported as hidden so a terminal or an agent transcript never holds a secret.
 *
 * @method static array<string, array<string, mixed>> run(?string $group = null)
 */
final class ReadSettingsAction
{
    use AsFake;
    use AsObject;

    public const string HIDDEN = '(hidden)';

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    public static function properties(string $class): array
    {
        return array_values(array_map(
            fn (ReflectionProperty $property): string => $property->getName(),
            array_filter(
                new ReflectionClass($class)->getProperties(ReflectionProperty::IS_PUBLIC),
                fn (ReflectionProperty $property): bool => ! $property->isStatic() && $property->getDeclaringClass()->getName() !== Settings::class,
            ),
        ));
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    public static function encrypted(string $class): array
    {
        return method_exists($class, 'encrypted') ? array_values((array) $class::encrypted()) : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function handle(?string $group = null): array
    {
        $classes = RegisteredSettingsClasses::byGroup();

        if ($group !== null) {
            throw_unless(
                isset($classes[$group]),
                InvalidArgumentException::class,
                sprintf('No settings group is called [%s]. Groups: %s.', $group, implode(', ', array_keys($classes)) ?: 'none'),
            );

            $classes = [$group => $classes[$group]];
        }

        $values = [];

        foreach ($classes as $groupName => $class) {
            $values[$groupName] = $this->values($class);
        }

        return $values;
    }

    /**
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    private function values(string $class): array
    {
        /** @var Settings $settings */
        $settings = resolve($class);
        $hidden = self::encrypted($class);
        $values = [];

        foreach (self::properties($class) as $property) {
            $values[$property] = in_array($property, $hidden, true) ? self::HIDDEN : $settings->{$property};
        }

        return $values;
    }
}
