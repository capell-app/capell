<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Columns\Page;

use BackedEnum;
use Capell\Admin\Actions\Pages\ResolvePageAvailabilityStateAction;
use Capell\Admin\Data\RecordStateData;
use Capell\Core\Models\Page;
use Filament\Tables\Columns\TextColumn;

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
            ->getStateUsing(fn (Page $record): ?string => $this->resolveState($record)?->shortLabel ?? $this->resolveState($record)?->label)
            ->tooltip(fn (Page $record): ?string => $this->resolveState($record)?->description)
            ->color(fn (Page $record): string => $this->resolveState($record)?->color ?? 'gray')
            ->icon(fn (Page $record): BackedEnum|string|null => $this->resolveState($record)?->icon);
    }

    private function resolveState(Page $page): ?RecordStateData
    {
        $key = $page->getKey() ?? spl_object_id($page);

        if (! array_key_exists($key, $this->states)) {
            $this->states[$key] = ResolvePageAvailabilityStateAction::run($page)->state();
        }

        return $this->states[$key];
    }
}
