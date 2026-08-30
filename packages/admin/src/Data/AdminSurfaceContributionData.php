<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Capell\Admin\Enums\AdminSurfaceContributionType;
use Capell\Core\Support\Extensions\ExtensionPosition;
use Spatie\LaravelData\Data;

final class AdminSurfaceContributionData extends Data
{
    public function __construct(
        public readonly AdminSurfaceContributionType $type,
        public readonly string $class,
        public readonly string $key,
        public readonly ?string $group = null,
        public readonly string $name = 'default',
        public readonly ?string $tag = null,
        public readonly string $owner = 'capell-app/admin',
        public readonly ?ExtensionPosition $position = null,
        public readonly string $source = self::class,
    ) {}

    public static function page(
        string $class,
        string $owner = 'capell-app/admin',
        ?ExtensionPosition $position = null,
        string $source = self::class,
    ): self {
        return new self(
            AdminSurfaceContributionType::Page,
            $class,
            $class,
            owner: $owner,
            position: $position,
            source: $source,
        );
    }

    public static function resource(
        string $class,
        string $group,
        string $name = 'default',
        string $owner = 'capell-app/admin',
        ?ExtensionPosition $position = null,
        string $source = self::class,
    ): self {
        return new self(
            AdminSurfaceContributionType::Resource,
            $class,
            sprintf('resource:%s:%s', $group, $name),
            $group,
            $name,
            owner: $owner,
            position: $position,
            source: $source,
        );
    }

    public static function widget(
        string $class,
        string $owner = 'capell-app/admin',
        ?ExtensionPosition $position = null,
        string $source = self::class,
    ): self {
        return new self(
            AdminSurfaceContributionType::Widget,
            $class,
            $class,
            owner: $owner,
            position: $position,
            source: $source,
        );
    }

    public static function panelExtender(
        string $class,
        string $owner = 'capell-app/admin',
        ?ExtensionPosition $position = null,
        string $source = self::class,
    ): self {
        return new self(
            AdminSurfaceContributionType::PanelExtender,
            $class,
            $class,
            owner: $owner,
            position: $position,
            source: $source,
        );
    }

    public static function configurator(
        string $class,
        string $group,
        string $name,
        string $owner = 'capell-app/admin',
        ?ExtensionPosition $position = null,
        string $source = self::class,
    ): self {
        return new self(
            AdminSurfaceContributionType::Configurator,
            $class,
            sprintf('configurator:%s:%s', $group, $name),
            $group,
            $name,
            owner: $owner,
            position: $position,
            source: $source,
        );
    }

    public static function schemaExtender(
        string $class,
        string $tag,
        string $owner = 'capell-app/admin',
        ?ExtensionPosition $position = null,
        string $source = self::class,
    ): self {
        return new self(
            AdminSurfaceContributionType::SchemaExtender,
            $class,
            sprintf('schema_extender:%s:%s', $tag, $class),
            tag: $tag,
            owner: $owner,
            position: $position,
            source: $source,
        );
    }
}
