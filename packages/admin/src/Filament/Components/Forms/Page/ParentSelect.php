<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Page;

use Capell\Admin\Contracts\Extenders\PageTableExtender;
use Capell\Admin\Filament\Components\Forms\PageSelect;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Closure;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ParentSelect extends PageSelect
{
    private ?Schema $relationshipSchema = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHintEditAction()
            ->modifySelectOptionsQueryUsing(fn (Builder $query): Builder => $this->modifyParentQuery($query))
            ->rules([
                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                    if (blank($value)) {
                        return;
                    }

                    $parent = SiteScope::applyForCurrentActor(Page::query())
                        ->whereKey($value)
                        ->first();

                    if (! $parent instanceof Page) {
                        $fail(__('capell-admin::message.parent_page_not_accessible'));

                        return;
                    }

                    $siteId = $get('site_id');
                    if (blank($siteId)) {
                        $record = $this->getRecord();
                        $siteId = $record instanceof Pageable ? $record->site_id : null;
                    }

                    if ($siteId !== null && $parent->site_id !== (int) $siteId) {
                        $fail(__('capell-admin::message.parent_page_not_accessible'));
                    }
                },
            ])
            ->hidden(function (Get $get): bool {
                $typeId = $get('blueprint_id');
                if ($typeId === null || $typeId === 0) {
                    return false;
                }

                $type = Blueprint::query()->pageType()->find($typeId);

                if ($type === null) {
                    return true;
                }

                return ($type->admin['without_parent_page'] ?? false) === true;
            });
    }

    public function setupRelation(string $relation, Schema $schema): self
    {
        $this->relationshipSchema = $schema;

        return $this;
    }

    public function qualifiedForeignKeyName(string $key): self
    {
        return $this;
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    private function modifyParentQuery(Builder $query): Builder
    {
        $base = $query;

        foreach (app()->tagged(PageTableExtender::TAG) as $extender) {
            if ($extender instanceof PageTableExtender) {
                $base = $extender->modifyQuery($base);
            }
        }

        /** @var Builder<Page> $base */
        $record = $this->getRecord();

        if ($record instanceof Page) {
            $descendantIds = $record->descendants?->pluck('id')->toArray();
            $operation = $this->relationshipSchema?->getOperation();

            $base = $base->when(
                $operation !== 'replicate',
                fn (Builder $q): Builder => $q->where('pages.id', '!=', $record->id),
            )
                ->when(
                    $descendantIds !== null && count($descendantIds) > 0,
                    fn (Builder $q): Builder => $q->whereNotIn('pages.id', $descendantIds),
                );
        }

        return $base;
    }
}
