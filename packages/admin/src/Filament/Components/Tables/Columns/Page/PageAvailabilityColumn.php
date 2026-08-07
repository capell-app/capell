<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Columns\Page;

use BackedEnum;
use Capell\Admin\Actions\Pages\ResolvePageAvailabilityStateAction;
use Capell\Admin\Data\RecordStateData;
use Capell\Core\Contracts\Pageable;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

final class PageAvailabilityColumn extends TextColumn
{
    /** @var array<int|string, RecordStateData|null> */
    private array $states = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::table.page_availability'))
            ->badge()
            ->alignCenter()
            ->width(0)
            ->toggleable()
            ->getStateUsing(fn (Model&Pageable $record): ?string => $this->stateLabel($record))
            ->tooltip(fn (Model&Pageable $record): ?string => $this->resolveState($record)?->description)
            ->color(fn (Model&Pageable $record): string => $this->stateColor($record))
            ->icon(fn (Model&Pageable $record): BackedEnum|string|Htmlable|null => $this->resolveState($record)?->icon);
    }

    /**
     * @template TModel of Model
     *
     * @param  Model&Pageable<TModel>  $page
     */
    private function resolveState(Model&Pageable $page): ?RecordStateData
    {
        $key = $page->getKey() ?? spl_object_id($page);

        if (! array_key_exists($key, $this->states)) {
            $this->states[$key] = ResolvePageAvailabilityStateAction::run($page)->state();
        }

        return $this->states[$key];
    }

    /**
     * @template TModel of Model
     *
     * @param  Model&Pageable<TModel>  $page
     */
    private function stateLabel(Model&Pageable $page): ?string
    {
        $state = $this->resolveState($page);

        return $state instanceof RecordStateData ? $state->shortLabel ?? $state->label : (null);
    }

    /**
     * @template TModel of Model
     *
     * @param  Model&Pageable<TModel>  $page
     */
    private function stateColor(Model&Pageable $page): string
    {
        $state = $this->resolveState($page);

        return $state instanceof RecordStateData ? $state->color : 'gray';
    }
}
