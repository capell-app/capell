<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Filters;

use Capell\Admin\Filament\Components\Forms\PageMorphToSelect;
use Capell\Core\Contracts\Pageable;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class PageSelectFilter extends Filter
{
    protected ?Closure $modifySelectQueryUsing = null;

    protected bool $multiple = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema([
            PageMorphToSelect::make()
                ->modifyKeySelectUsing(
                    fn (PageMorphToSelect $component, Select $select): Select => $select->multiple($this->multiple),
                )
                ->modifyKeySelectOptionsQueryUsing($this->getModifySelectQueryUsing(...)),
        ])
            ->query(function (Builder $query, array $data): void {
                if (! isset($data['pageable_type'], $data['pageable_id'])) {
                    return;
                }

                if (blank($data['pageable_type']) || blank($data['pageable_id'])) {
                    return;
                }

                $query->whereHasMorph(
                    'pageable',
                    $data['pageable_type'],
                    function (Builder $query) use ($data): void {
                        $query->whereKey($data['pageable_id']);
                    },
                );
            })
            ->indicateUsing(function (array $data): ?string {
                if (! isset($data['pageable_type'], $data['pageable_id'])) {
                    return null;
                }

                if (blank($data['pageable_type']) || blank($data['pageable_id'])) {
                    return null;
                }

                /** @var class-string<Pageable<Model>&Model> $modelClass */
                $modelClass = Relation::getMorphedModel($data['pageable_type']);

                if (is_array($data['pageable_id'])) {
                    $names = $modelClass::query()
                        ->whereKey($data['pageable_id'])
                        ->pluck('name')
                        ->implode(', ');
                } else {
                    $names = $modelClass::query()
                        ->whereKey($data['pageable_id'])
                        ->value('name');
                }

                return __('capell-admin::filter.page', ['search' => $names]);
            });
    }

    public static function getDefaultName(): ?string
    {
        return 'page_filter';
    }

    public function modifySelectQueryUsing(?Closure $callback): static
    {
        $this->modifySelectQueryUsing = $callback;

        return $this;
    }

    public function multiple(bool $multiple = true): static
    {
        $this->multiple = $multiple;

        return $this;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>|null
     */
    private function getModifySelectQueryUsing(Select $select, Builder $query): ?Builder
    {
        if (! $this->modifySelectQueryUsing instanceof Closure) {
            return null;
        }

        return $this->evaluate($this->modifySelectQueryUsing, ['select' => $select, 'query' => $query]);
    }
}
