<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Capell\Admin\Enums\AdminSurfaceContributionType;
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
    ) {}

    public static function page(string $class): self
    {
        return new self(AdminSurfaceContributionType::Page, $class, $class);
    }

    public static function resource(string $class, string $group, string $name = 'default'): self
    {
        return new self(
            AdminSurfaceContributionType::Resource,
            $class,
            sprintf('resource:%s:%s', $group, $name),
            $group,
            $name,
        );
    }

    public static function widget(string $class): self
    {
        return new self(AdminSurfaceContributionType::Widget, $class, $class);
    }

    public static function panelExtender(string $class): self
    {
        return new self(AdminSurfaceContributionType::PanelExtender, $class, $class);
    }

    public static function configurator(string $class, string $group, string $name): self
    {
        return new self(
            AdminSurfaceContributionType::Configurator,
            $class,
            sprintf('configurator:%s:%s', $group, $name),
            $group,
            $name,
        );
    }

    public static function schemaExtender(string $class, string $tag): self
    {
        return new self(
            AdminSurfaceContributionType::SchemaExtender,
            $class,
            sprintf('schema_extender:%s:%s', $tag, $class),
            tag: $tag,
        );
    }
}
