<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Tables\Columns\DateColumn;
use Capell\Admin\Tests\Unit\Filament\Components\Tables\Columns\Fixtures\DateColumnTableLivewire;
use Capell\Core\Models\Site;
use Capell\Tests\Fixtures\Models\User;
use Carbon\CarbonImmutable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

it('formats created and updated timestamps with userstamp context when available', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-30 12:00:00'));

    $creator = User::factory()->createOne(['name' => 'Creator Admin']);
    $editor = User::factory()->createOne(['name' => 'Editor Admin']);
    $site = Site::factory()->createOne([
        'created_at' => CarbonImmutable::parse('2026-05-29 10:00:00'),
        'updated_at' => CarbonImmutable::parse('2026-05-30 09:30:00'),
    ]);
    $site->setRelation('creator', $creator);
    $site->setRelation('editor', $editor);

    $blankSite = Site::factory()->createOne()
        ->forceFill([
            'created_at' => null,
            'updated_at' => null,
        ]);

    $createdAt = formattedDateColumnState('created_at', $site);
    $updatedAt = formattedDateColumnState('updated_at', $site);
    $updatedWithoutEditor = formattedDateColumnState('updated_at', $site->unsetRelation('editor'));

    expect($createdAt)->toContain('x-tooltip.raw', 'Creator Admin')
        ->and($updatedAt)->toContain('x-tooltip.raw', 'Editor Admin')
        ->and($updatedWithoutEditor)->not->toContain('x-tooltip.raw', 'Editor Admin')
        ->and(formattedDateColumnState('created_at', $blankSite))->toBe('')
        ->and(formattedDateColumnState('updated_at', $blankSite))->toBe('');

    CarbonImmutable::setTestNow();
});

it('formats deleted timestamps only when the trashed filter is active', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-30 12:00:00'));

    $destroyer = User::factory()->createOne(['name' => 'Destroyer Admin']);
    $site = Site::factory()->createOne([
        'deleted_at' => CarbonImmutable::parse('2026-05-28 08:15:00'),
    ]);
    $site->setRelation('destroyer', $destroyer);

    $notDeletedSite = Site::factory()->createOne()
        ->forceFill(['deleted_at' => null]);

    $hiddenColumn = mountedDateColumn('deleted_at', ['trashed' => ['value' => null]]);
    $visibleColumn = mountedDateColumn('deleted_at', ['trashed' => ['value' => 'with']]);

    expect($hiddenColumn->isVisible())->toBeFalse()
        ->and($visibleColumn->isVisible())->toBeTrue()
        ->and(formattedDateColumnState('deleted_at', $site, $visibleColumn))->toContain('x-tooltip.raw', 'Destroyer Admin')
        ->and(formattedDateColumnState('deleted_at', $site->unsetRelation('destroyer'), $visibleColumn))->not->toContain('Destroyer Admin')
        ->and(formattedDateColumnState('deleted_at', $notDeletedSite, $visibleColumn))->toBe('');

    CarbonImmutable::setTestNow();
});

/**
 * @param  array<string, mixed>|null  $filterState
 */
function mountedDateColumn(string $name, ?array $filterState = null): DateColumn
{
    $livewire = new DateColumnTableLivewire;
    $livewire->tableFilters = $filterState;

    $table = Table::make($livewire);
    $livewire->mountTableForDateColumnTest($table);

    return DateColumn::make($name)
        ->table($table);
}

function formattedDateColumnState(string $name, Model $record, ?DateColumn $column = null): string
{
    $column ??= mountedDateColumn($name);
    $column->record($record);

    return (string) $column->formatState($column->getState());
}
