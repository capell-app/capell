<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Extensions;

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

final class ExtensionsPageActionRegistry
{
    /** @var array<int|string, Action|ActionGroup|Closure(ExtensionsPage): (Action|ActionGroup)> */
    private array $headerActions = [];

    /** @var array<int|string, Action|ActionGroup|Closure(ExtensionsPage): (Action|ActionGroup)> */
    private array $headerActionGroupActions = [];

    /** @var array<int|string, Action|Closure(ExtensionsPage): Action> */
    private array $tableActions = [];

    /** @param  Action|ActionGroup|Closure(ExtensionsPage): (Action|ActionGroup)  $action */
    public function registerHeaderAction(Action|ActionGroup|Closure $action, ?string $key = null): void
    {
        if ($key === null) {
            $this->headerActions[] = $action;

            return;
        }

        $this->headerActions[$key] = $action;
    }

    /** @param  Action|ActionGroup|Closure(ExtensionsPage): (Action|ActionGroup)  $action */
    public function registerHeaderActionGroupAction(Action|ActionGroup|Closure $action, ?string $key = null): void
    {
        if ($key === null) {
            $this->headerActionGroupActions[] = $action;

            return;
        }

        $this->headerActionGroupActions[$key] = $action;
    }

    /** @param  Action|Closure(ExtensionsPage): Action  $action */
    public function registerTableAction(Action|Closure $action, ?string $key = null): void
    {
        if ($key === null) {
            $this->tableActions[] = $action;

            return;
        }

        $this->tableActions[$key] = $action;
    }

    /** @return array<int, Action|ActionGroup> */
    public function headerActions(ExtensionsPage $page): array
    {
        return $this->resolveActions($this->headerActions, $page);
    }

    /** @return array<int, Action|ActionGroup> */
    public function headerActionGroupActions(ExtensionsPage $page): array
    {
        return $this->resolveActions($this->headerActionGroupActions, $page);
    }

    /** @return array<int, Action|ActionGroup> */
    public function tableActions(ExtensionsPage $page): array
    {
        return $this->resolveActions($this->tableActions, $page);
    }

    /**
     * @param  array<int|string, Action|ActionGroup|Closure(ExtensionsPage): (Action|ActionGroup)>  $actions
     * @return array<int, Action|ActionGroup>
     */
    private function resolveActions(array $actions, ExtensionsPage $page): array
    {
        return array_values(array_map(
            fn (Action|ActionGroup|Closure $action): Action|ActionGroup => $action instanceof Closure
                ? $action($page)
                : $this->cloneAction($action),
            $actions,
        ));
    }

    private function cloneAction(Action|ActionGroup $action): Action|ActionGroup
    {
        $clone = (clone $action)->group(null);

        if ($clone instanceof ActionGroup) {
            $clone->actions(array_map(
                fn (Action|ActionGroup $groupedAction): Action|ActionGroup => $this->cloneAction($groupedAction),
                $clone->getActions(),
            ));
        }

        return $clone;
    }
}
