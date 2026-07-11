<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Filters;

use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Group;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class DateFilter extends Filter
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->schema([
            Group::make([
                DatePicker::make('from')
                    ->label(trans('capell-admin::form.from'))
                    ->placeholder(trans('capell-admin::generic.from_placeholder')),
                DatePicker::make('until')
                    ->label(trans('capell-admin::form.until'))
                    ->placeholder(trans('capell-admin::generic.until_placeholder')),
            ])
                ->columns(['default' => 1, 'sm' => 2])
                ->dense(),
        ])
            ->query(function (Builder $query, array $data): void {
                if (filled($data['from'])) {
                    $query->whereDate($this->getName(), '>=', $data['from']);
                }

                if (filled($data['until'])) {
                    $query->whereDate($this->getName(), '<=', $data['until']);
                }
            })
            ->indicateUsing(function (array $data): ?string {
                if (blank($data['from']) && blank($data['until'])) {
                    return null;
                }

                if (filled($data['from']) && filled($data['until'])) {
                    return trans('capell-admin::generic.indicate_from_to', [
                        'from' => $data['from'],
                        'until' => $data['until'],
                    ]);
                }

                if ($data['from'] !== null && $data['from'] !== '') {
                    return trans('capell-admin::generic.indicate_from', ['date' => $data['from']]);
                }

                if ($data['until'] !== null && $data['until'] !== '') {
                    return trans('capell-admin::generic.indicate_until', ['date' => $data['until']]);
                }

                return null;
            });
    }
}
