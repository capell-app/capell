<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Page;

use Capell\Admin\Filament\Components\Forms\BlueprintSelect as BaseBlueprintSelect;
use Capell\Admin\Filament\Contracts\HasPageResource;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Models\Blueprint;
use Closure;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class BlueprintSelect extends BaseBlueprintSelect
{
    protected null|string|Closure $pageGroup = null;

    protected null|BlueprintSubjectEnum|string $type = BlueprintSubjectEnum::Page;

    protected null|bool|Closure $withSystemTypes = null;

    protected function setUp(?string $label = null): void
    {
        parent::setUp($label);

        $this->modifySelectOptionsQueryUsing(
            /**
             * @param  Builder<Blueprint>  $query
             */
            function (Builder $query, EditRecord|CreateRecord|ListRecords|HasPageResource $livewire): Builder {
                $group = BlueprintSubjectEnum::Page->value;

                if ($this->pageGroup !== null) {
                    $group = $this->evaluate($this->pageGroup);
                } elseif (method_exists($livewire, 'getResource')) {
                    $resource = $livewire->getResource();

                    if (method_exists($resource, 'getResourceName')) {
                        $group = $resource::getResourceName();
                    }
                }

                $groups = [];

                if ($group) {
                    $groups[] = $group;
                }

                if ($this->hasSystemPages()) {
                    $groups[] = BlueprintGroupEnum::System->value;
                }

                if ($groups === []) {
                    return $query;
                }

                return $query->where(
                    fn (Builder $query): Builder => $query->whereIn('group', $groups)
                        ->orWhereNull('group'),
                );
            },
        );

        $this->afterEditOptionActionUpdated(function (self $component, Action $action, Pageable $record): void {
            $actionData = $action->getData();
            $contentStructure = $actionData['meta']['content_structure']
                ?? $actionData['meta.content_structure']
                ?? null;

            if ($contentStructure !== null && $record->blueprint->content_structure !== $contentStructure) {
                $livewire = $component->getLivewire();
                if ($livewire instanceof EditPage) {
                    $livewire->pageTypeContentStructureUpdated($contentStructure);
                }
            }
        });
    }

    public function withSystemTypes(bool|callable $callback): self
    {
        $this->withSystemTypes = is_bool($callback)
            ? $callback
            : $callback(...);

        return $this;
    }

    public function pageGroup(string|Closure $pageGroup): self
    {
        $this->pageGroup = $pageGroup;

        return $this;
    }

    protected function hasSystemPages(): bool
    {
        if (is_callable($this->withSystemTypes)) {
            return $this->evaluate($this->withSystemTypes);
        }

        return $this->withSystemTypes ?? true;
    }
}
