<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Aimeos\Nestedset\NestedSet;
use Capell\Admin\Filament\Actions\HintEditAction;
use Capell\Admin\Filament\Concerns\HasCustomSelectOption;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Page;
use Closure;
use CodeWithDennis\FilamentSelectTree\SelectTree;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class PageRelationSelect extends SelectTree
{
    use HasCustomSelectOption;

    private ?Closure $modifyRelationQueryUsing = null;

    private ?Closure $modifyTypeQueryUsing = null;

    private null|string|Closure $pageGroup = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.page'))
            ->enableBranchNode();
    }

    public function getModifyRelationQueryUsing(): ?Closure
    {
        return $this->modifyRelationQueryUsing;
    }

    public function getModifyTypeQueryUsing(): ?Closure
    {
        return $this->modifyTypeQueryUsing;
    }

    public function modifyRelationQueryUsing(?Closure $callback): static
    {
        $this->modifyRelationQueryUsing = $callback;

        return $this;
    }

    public function modifyTypeQueryUsing(?Closure $callback): static
    {
        $this->modifyTypeQueryUsing = $callback;

        return $this;
    }

    public function qualifiedForeignKeyName(string $key): self
    {
        throw_if(mb_strpos($key, '.') === false, InvalidArgumentException::class, 'The qualifiedForeignKeyName must contain the table alias/name.');

        return $this;
    }

    public function pageGroup(string|Closure $assetType): self
    {
        $this->pageGroup = $assetType;

        return $this;
    }

    public function setupRelation(string $relation, Schema $schema): self
    {
        $this->relationship(
            relationship: $relation,
            titleAttribute: 'name',
            parentAttribute: 'parent_id',
            modifyQueryUsing: fn (Builder $query, ?Pageable $record, Get $get, self $component): Builder => $component->modifyRelationQuery(
                $query,
                operation: $schema->getOperation(),
                siteId: filled($get('site_id')) ? (int) $get('site_id') : $record?->site_id,
            ),
        );

        return $this;
    }

    public function withHintEditAction(): self
    {
        $this->hintAction(
            HintEditAction::make('edit-page')
                ->visible(fn (null|string|int $state, string $operation): bool => $operation !== 'create' && filled($state))
                ->url(function (null|string|int $state): ?string {
                    if ($state === 0 || ($state === '' || $state === '0') || $state === null) {
                        return null;
                    }

                    /** @var class-string<Page> $model */
                    $model = Page::class;

                    /** @var ?Page $page */
                    $page = $model::query()->withWhereHas('blueprint:id,admin')->find($state);

                    if ($page === null) {
                        return null;
                    }

                    return GetEditPageResourceUrlAction::run($page);
                }),
        );

        return $this;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function modifyRelationQuery(Builder $query, string $operation, ?int $siteId): Builder
    {
        $query->with('ancestors')
            ->when($siteId, fn (Builder $query) => $query->where($query->qualifyColumn('site_id'), $siteId))
            ->whereHas(
                'blueprint',
                fn (BuilderContract $query): BuilderContract => $query->when(
                    $this->pageGroup,
                    function (BuilderContract $query): void {
                        $this->modifyPageGroupQuery($query);
                    },
                )
                    ->when(
                        $this->modifyTypeQueryUsing,
                        fn (BuilderContract $query): mixed => $this->evaluate($this->modifyTypeQueryUsing, ['query' => $query]),
                    ),
            )
            ->orderBy($query->qualifyColumn('site_id'))
            ->orderBy(NestedSet::LFT, 'asc');

        if ($this->modifyRelationQueryUsing instanceof Closure) {
            $this->evaluate($this->modifyRelationQueryUsing, ['query' => $query]);
        }

        return $query;
    }

    private function modifyPageGroupQuery(BuilderContract $query): void
    {
        if ($this->pageGroup === null) {
            return;
        }

        if (is_string($this->pageGroup)) {
            $query->adminResource($this->pageGroup);

            return;
        }

        $this->evaluate($this->pageGroup, ['query' => $query]);
    }
}
