<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\AdminSurfaceContributionType;
use Capell\Core\Data\Extensions\ExtensionOrderDiagnosticData;
use Capell\Core\Exceptions\ExtensionContributionConflictException;
use Capell\Core\Support\Extensions\ExtensionOrderResolver;
use Capell\Core\Support\Extensions\ExtensionPosition;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Widgets\Widget;
use LogicException;

final class AdminSurfaceContributionRegistry
{
    /** @var array<string, array<string, AdminSurfaceContributionData>> */
    private array $contributions = [];

    private bool $frozen = false;

    public function __construct(private readonly ?ExtensionOrderResolver $orderResolver = null) {}

    public function register(AdminSurfaceContributionData $contribution): void
    {
        if ($this->frozen) {
            throw ExtensionContributionConflictException::frozen($contribution->owner, $contribution->source);
        }

        $existing = $this->contributions[$contribution->type->value][$contribution->key] ?? null;
        if ($existing instanceof AdminSurfaceContributionData) {
            if ($this->same($existing, $contribution)) {
                return;
            }

            throw ExtensionContributionConflictException::duplicate(
                $contribution->key,
                $existing->owner,
                $existing->source,
                $contribution->owner,
                $contribution->source,
            );
        }

        $this->contributions[$contribution->type->value][$contribution->key] = $contribution;
    }

    public function replace(AdminSurfaceContributionData $contribution): void
    {
        if ($this->frozen) {
            throw ExtensionContributionConflictException::frozen($contribution->owner, $contribution->source);
        }

        if (! isset($this->contributions[$contribution->type->value][$contribution->key])) {
            throw new LogicException("Cannot replace missing extension key [{$contribution->key}].");
        }

        $this->contributions[$contribution->type->value][$contribution->key] = $contribution;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /** @return list<ExtensionOrderDiagnosticData> */
    public function orderingDiagnostics(AdminSurfaceContributionType $type): array
    {
        $resolver = $this->orderResolver ?? new ExtensionOrderResolver;
        $resolver->resolve(
            array_values($this->contributions[$type->value] ?? []),
            static fn (AdminSurfaceContributionData $item, int $index): string => $item->key,
            static fn (AdminSurfaceContributionData $item): ExtensionPosition => $item->position ?? ExtensionPosition::priority(0),
        );

        return $resolver->diagnostics();
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

        foreach ($this->ordered(AdminSurfaceContributionType::SchemaExtender) as $contribution) {
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
        $this->frozen = false;
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

        foreach ($this->ordered($type) as $contribution) {
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

        foreach ($this->ordered($type) as $contribution) {
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

    /** @return list<AdminSurfaceContributionData> */
    private function ordered(AdminSurfaceContributionType $type): array
    {
        $items = array_values($this->contributions[$type->value] ?? []);

        return ($this->orderResolver ?? new ExtensionOrderResolver)->resolve(
            $items,
            static fn (AdminSurfaceContributionData $item, int $index): string => $item->key,
            static fn (AdminSurfaceContributionData $item): ExtensionPosition => $item->position ?? ExtensionPosition::priority(0),
        );
    }

    private function same(AdminSurfaceContributionData $left, AdminSurfaceContributionData $right): bool
    {
        return $left->type === $right->type
            && $left->class === $right->class
            && $left->key === $right->key
            && $left->group === $right->group
            && $left->name === $right->name
            && $left->tag === $right->tag
            && $left->owner === $right->owner
            && $this->positionKey($left->position) === $this->positionKey($right->position)
            && $left->source === $right->source;
    }

    private function positionKey(?ExtensionPosition $position): string
    {
        if ($position === null) {
            return '';
        }

        return implode(':', [$position->kind, (string) $position->priority, $position->anchor ?? '']);
    }
}
