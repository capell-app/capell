<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Admin\Contracts\ConfiguratorInterface;
use Capell\Admin\Contracts\ConfiguratorTypeEnumInterface;
use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Filament\Schemas\Schema;

/**
 * @mixin ConfiguratorInterface
 *
 * @method array<int, mixed> make(Schema $schema)
 */
trait HasConfigurator
{
    protected static ?int $sort = null;

    protected ?ConfiguratorContextData $context = null;

    public static function configure(Schema $schema, ?ConfiguratorContextData $context = null): Schema
    {
        /** @phpstan-ignore new.static (Configurator subclasses are deliberately constructed through this static factory.) */
        $configurator = new static;
        $configurator->context = $context;

        if ($context instanceof ConfiguratorContextData) {
            $schema->operation($context->operation);
        }

        return $schema->components($configurator->make($schema));
    }

    public static function getSort(): int
    {
        return static::$sort ?? -1;
    }

    public static function getKey(): string
    {
        $key = class_basename(static::class);

        $suffix = static::getConfiguratorType()->getName() . 'Configurator';

        return preg_replace(sprintf('/%s$/', $suffix), '', $key) ?? $key;
    }

    public static function getConfiguratorType(): ConfiguratorTypeEnumInterface
    {
        /** @phpstan-ignore staticProperty.notFound (Each configurator implementation supplies the static type property.) */
        return static::$configuratorType;
    }
}
