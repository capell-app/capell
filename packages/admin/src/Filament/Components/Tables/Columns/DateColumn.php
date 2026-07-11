<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Columns;

use Capell\Admin\Enums\FilamentColorEnum;
use Capell\Core\Models\Contracts\Userstampable;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class DateColumn extends TextColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->sortable()
            ->since()
            ->html()
            ->size('xs')
            ->color(FilamentColorEnum::LightGray->value)
            ->alignRight()
            ->extraAttributes(['class' => 'tracking-tight italic'])
            ->width(0);

        match ($this->getName()) {
            'created_at' => $this->createdAt(),
            'updated_at' => $this->updatedAt(),
            'deleted_at' => $this->deletedAt(),
            default => $this->dateTimeTooltip(),
        };
    }

    private function createdAt(): TextColumn
    {
        return $this->label(__('capell-admin::table.created_at'))
            ->toggleable(isToggledHiddenByDefault: true)
            ->formatStateUsing(function (Model $record): HtmlString|string {
                $createdAt = CarbonImmutable::make($record->getAttribute('created_at'));
                if (! ($createdAt instanceof CarbonImmutable)) {
                    return '';
                }

                $label = e($createdAt->diffForHumans());

                $creatorModel = $record instanceof Userstampable
                    ? $record->creatorUser()
                    : ($record->relationLoaded('creator') ? $record->getRelation('creator') : null);
                $creatorName = $creatorModel instanceof Model ? (string) $creatorModel->getAttribute('name') : '';

                $tooltip = e((string) __('capell-admin::generic.created_by_at', [
                    'name' => $creatorName,
                    'date' => $createdAt->translatedFormat($this->getTable()->getDefaultDateTimeDisplayFormat()),
                ]));

                return new HtmlString(sprintf('<span x-tooltip.raw="%s">%s</span>', $tooltip, $label));
            });
    }

    private function deletedAt(): TextColumn
    {
        return $this->label(__('capell-admin::table.deleted_at'))
            ->toggleable()
            ->visible(fn (HasTable $livewire): bool => ($livewire->getTableFilterState('trashed')['value'] ?? null) !== null)
            ->formatStateUsing(function (Model $record): HtmlString|string {
                $deletedAt = CarbonImmutable::make($record->getAttribute('deleted_at'));
                if (! ($deletedAt instanceof CarbonImmutable)) {
                    return '';
                }

                $label = e($deletedAt->diffForHumans());

                $name = null;
                if ($record instanceof Userstampable) {
                    $name = $record->destroyerUser()?->getAttribute('name');
                } elseif (method_exists($record, 'destroyer')) {
                    $name = $record->destroyer()->first()?->getAttribute('name');
                }

                if ($name === null) {
                    return new HtmlString($label);
                }

                $tooltip = e((string) __('capell-admin::generic.deleted_by_at', [
                    'name' => (string) $name,
                    'date' => $deletedAt->translatedFormat($this->getTable()->getDefaultDateTimeDisplayFormat()),
                ]));

                return new HtmlString(sprintf('<span x-tooltip.raw="%s">%s</span>', $tooltip, $label));
            });
    }

    private function updatedAt(): TextColumn
    {
        return $this->label(__('capell-admin::table.updated_at'))
            ->toggleable()
            ->formatStateUsing(function (Model $record): HtmlString|string {
                $updatedAt = CarbonImmutable::make($record->getAttribute('updated_at'));
                if (! ($updatedAt instanceof CarbonImmutable)) {
                    return '';
                }

                $label = e($updatedAt->diffForHumans());

                $editorModel = null;
                if ($record instanceof Userstampable) {
                    $editorModel = $record->editorUser();
                } elseif (method_exists($record, 'editor') && $record->relationLoaded('editor')) {
                    $editorModel = $record->getRelation('editor');
                }

                if (! ($editorModel instanceof Model)) {
                    return $label;
                }

                $editorName = (string) $editorModel->getAttribute('name');

                $tooltip = e((string) __('capell-admin::generic.updated_by_at', [
                    'name' => $editorName,
                    'date' => $updatedAt->translatedFormat($this->getTable()->getDefaultDateTimeDisplayFormat()),
                ]));

                return new HtmlString(sprintf('<span x-tooltip.raw="%s">%s</span>', $tooltip, $label));
            });
    }
}
