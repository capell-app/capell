<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\AdminSurfaceContributionType;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Widgets\Widget;

final class AdminSurfaceContributionRegistry
{
    /** @var array<string, array<string, AdminSurfaceContributionData>> */
    private array $contributions = [];

    public function register(AdminSurfaceContributionData $contribution): void
    {
        $this->contributions[$contribution->type->value][$contribution->key] = $contribution;
    }

    /** @return array<string, array<string, AdminSurfaceContributionData>> */
    public function all(): array
    {
        return $this->contributions;
    }

    /** @return list<class-string<Page>> */
    public function pages(): array
    {
        return $this->classesFor(AdminSurfaceContributionType::Page, Page::class);
    }

    /** @return list<class-string<resource>> */
    public function resources(): array
    {
        return $this->classesFor(AdminSurfaceContributionType::Resource, Resource::class);
    }

    /** @return list<class-string<Widget>> */
    public function widgets(): array
    {
        return $this->classesFor(AdminSurfaceContributionType::Widget, Widget::class);
    }

    /** @return list<class-string<AdminPanelExtender>> */
    public function panelExtenders(): array
    {
        return $this->classesFor(AdminSurfaceContributionType::PanelExtender, AdminPanelExtender::class);
    }

    /** @return array<string, class-string> */
    public function resourcesForGroup(string $group): array
    {
        return $this->namedClassesForGroup(AdminSurfaceContributionType::Resource, $group);
    }

    /** @return array<string, class-string> */
    public function configuratorsForGroup(string $group): array
    {
        return $this->namedClassesForGroup(AdminSurfaceContributionType::Configurator, $group);
    }

    /** @return list<class-string> */
    public function schemaExtendersForTag(string $tag): array
    {
        $classes = [];

        foreach ($this->contributions[AdminSurfaceContributionType::SchemaExtender->value] ?? [] as $contribution) {
            if ($contribution->tag !== $tag) {
                continue;
            }

            if (! class_exists($contribution->class)) {
                continue;
            }

            /** @var class-string $class */
            $class = $contribution->class;
            $classes[] = $class;
        }

        return $classes;
    }

    public function clear(): void
    {
        $this->contributions = [];
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $baseClass
     * @return list<class-string<T>>
     */
    private function classesFor(AdminSurfaceContributionType $type, string $baseClass): array
    {
        $classes = [];

        foreach ($this->contributions[$type->value] ?? [] as $contribution) {
            if (! is_subclass_of($contribution->class, $baseClass)) {
                continue;
            }

            /** @var class-string<T> $class */
            $class = $contribution->class;
            $classes[] = $class;
        }

        return $classes;
    }

    /** @return array<string, class-string> */
    private function namedClassesForGroup(AdminSurfaceContributionType $type, string $group): array
    {
        $classes = [];

        foreach ($this->contributions[$type->value] ?? [] as $contribution) {
            if ($contribution->group !== $group) {
                continue;
            }

            if (! class_exists($contribution->class)) {
                continue;
            }

            $classes[$contribution->name] = $contribution->class;
        }

        return $classes;
    }
}
