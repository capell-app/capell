<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Backup;

use Capell\Admin\Contracts\Extenders\PageExportExtender;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;

final class PageExportOptions
{
    /**
     * @return array<int, mixed>
     */
    public static function schema(bool $includeAllContexts = false): array
    {
        return [
            ...collect(app()->tagged(PageExportExtender::TAG))
                ->flatMap(fn (PageExportExtender $extender): array => $extender->getFormFields())
                ->all(),
            Checkbox::make('include_translations')
                ->label(__('capell-admin::exchanger.export.include_translations'))
                ->default(true),
            Checkbox::make('include_media')
                ->label(__('capell-admin::exchanger.export.include_media'))
                ->default(true),
            Checkbox::make('include_shared_relations')
                ->label(__('capell-admin::exchanger.export.include_shared_relations'))
                ->default(true),
            ...($includeAllContexts ? [
                Checkbox::make('include_all_contexts')
                    ->label(__('capell-admin::exchanger.export.include_all_contexts'))
                    ->default(false),
            ] : []),
            Textarea::make('note')
                ->label(__('capell-admin::exchanger.export.note'))
                ->rows(2),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function resolve(array $data, bool $includeAllContexts = false, bool $omitAllContexts = false): array
    {
        $options = [
            'include_translations' => (bool) ($data['include_translations'] ?? true),
            'include_media' => (bool) ($data['include_media'] ?? true),
            'include_shared_relations' => (bool) ($data['include_shared_relations'] ?? true),
        ];

        if (! $omitAllContexts) {
            $options['include_all_contexts'] = $includeAllContexts && (bool) ($data['include_all_contexts'] ?? false);
        }

        $options['note'] = $data['note'] ?? null;

        return $options;
    }
}
