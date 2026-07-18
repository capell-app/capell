<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Exports;

use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\PageUrl;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Override;

class RedirectExporter extends Exporter
{
    protected static ?string $model = PageUrl::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('url')
                ->label(__('capell-admin::table.source_url')),

            ExportColumn::make('target_url')
                ->label(__('capell-admin::table.target_url')),

            ExportColumn::make('status_code')
                ->label(__('capell-admin::table.status_code'))
                ->formatStateUsing(fn (PageUrl $record): int => $record->status_code->value ?? 301),

            ExportColumn::make('status')
                ->label(__('capell-admin::table.status'))
                ->formatStateUsing(fn (PageUrl $record): string => $record->status ? 'active' : 'disabled'),

            ExportColumn::make('is_manual')
                ->label(__('capell-admin::table.is_manual'))
                ->formatStateUsing(fn (PageUrl $record): string => $record->is_manual ? 'yes' : 'no'),

            ExportColumn::make('site.name')
                ->label(__('capell-admin::form.site')),

            ExportColumn::make('language.name')
                ->label(__('capell-admin::form.language')),

            ExportColumn::make('hit_count')
                ->label(__('capell-admin::table.hit_count')),

            ExportColumn::make('last_hit_at')
                ->label(__('capell-admin::table.last_hit_at')),

            ExportColumn::make('notes')
                ->label(__('capell-admin::form.notes')),

            ExportColumn::make('created_at'),
        ];
    }

    #[Override]
    public static function modifyQuery(Builder $query): Builder
    {
        return $query
            ->redirects()
            ->tap(fn (Builder $query): Builder => SiteScope::applyForCurrentActor($query))
            ->with(['site', 'language']);
    }

    #[Override]
    public static function getCompletedNotificationBody(Export $export): string
    {
        return __('capell-admin::message.redirect_export_complete', [
            'count' => number_format($export->successful_rows),
        ]);
    }

    #[Override]
    public static function getModel(): string
    {
        return PageUrl::class;
    }
}
