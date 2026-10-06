<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Tables\Columns\BadgeableColumn;
use Capell\Admin\Tests\Unit\Filament\Components\Tables\Columns\Fixtures\DateColumnTableLivewire;
use Capell\Core\Models\Site;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

it('preserves ordinary prefix and suffix affixes with the current Filament method signatures', function (): void {
    $column = BadgeableColumn::make('name')->prefix('Before')->suffix('After');

    expect($column->getPrefix())->toBe('Before')
        ->and($column->getSuffix('state', new Site))->toBe('After');
});

it('renders a native default badge while escaping an ordinary suffix', function (): void {
    $column = BadgeableColumn::make('name')
        ->record(new Site(['name' => 'Site', 'default' => true]))
        ->defaultBadge()
        ->suffix('<script>unsafe</script>');

    $suffix = $column->getSuffix();

    expect($suffix)->toBeInstanceOf(Htmlable::class)
        ->and($suffix instanceof Htmlable ? $suffix->toHtml() : $suffix)->toContain('fi-badge', __('capell-admin::generic.default'), '&lt;script&gt;unsafe&lt;/script&gt;', 'gap:1.4rem')
        ->not->toContain('<script>');

    $formatted = $column->formatState('<b>Site</b>');

    expect($formatted instanceof Htmlable ? $formatted->toHtml() : $formatted)
        ->toContain('&lt;b&gt;Site&lt;/b&gt;', 'fi-badge', __('capell-admin::generic.default'));
});

it('omits the badge for non-default records and when disabled', function (): void {
    $column = BadgeableColumn::make('name')->defaultBadge()->suffix('After');

    expect($column->record(new Site(['default' => false]))->getSuffix())->toBe('After')
        ->and($column->record(new Site(['default' => true]))->defaultBadge(false)->getSuffix())->toBe('After');
});

it('passes the supplied state and related record to ordinary affix callbacks', function (): void {
    $column = BadgeableColumn::make('name')
        ->table(badgeableColumnTestTable())
        ->record(Site::factory()->createOne(['name' => 'Primary']))
        ->suffix(fn (mixed $state, Site $relatedRecord): string => (is_string($state) ? $state : 'Unexpected state') . ':' . $relatedRecord->name);
    $related = new Site(['name' => 'Related']);

    // Filament 5.10 adds explicit state/related-record arguments. Earlier
    // supported versions evaluate callbacks against the column's own record.
    $supportsAffixContext = new ReflectionMethod(TextColumn::class, 'getPrefix')->getNumberOfParameters() > 0;

    expect($column->getSuffix('Suffix state', $related))->toBe($supportsAffixContext ? 'Suffix state:Related' : 'Primary:Primary');
});

it('renders an Htmlable prefix without requiring it to be Stringable', function (): void {
    $prefix = new class implements Htmlable
    {
        #[Override]
        public function toHtml(): string
        {
            return '<em>Prefix HTML</em>';
        }
    };
    $column = BadgeableColumn::make('name')
        ->record(new Site(['name' => 'Site']))
        ->prefix($prefix);
    $rendered = $column->getPrefix();

    expect($rendered instanceof Htmlable ? $rendered->toHtml() : $rendered)
        ->toBe('<em>Prefix HTML</em>');
});

it('keeps zero-argument affix callbacks bound to the column state', function (): void {
    $column = BadgeableColumn::make('name')
        ->table(badgeableColumnTestTable())
        ->record(Site::factory()->createOne(['name' => 'Column state']))
        ->prefix(fn (mixed $state): string => $state === 'Column state' ? 'Column state' : 'Unexpected state')
        ->suffix(fn (mixed $state): string => $state === 'Column state' ? 'Column state' : 'Unexpected state');

    expect($column->getPrefix())->toBe('Column state')
        ->and($column->getSuffix())->toBe('Column state');
});

function badgeableColumnTestTable(): Table
{
    $livewire = new DateColumnTableLivewire;
    $table = Table::make($livewire);
    $livewire->mountTableForDateColumnTest($table);

    return $table;
}
