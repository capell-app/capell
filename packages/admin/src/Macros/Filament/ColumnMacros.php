<?php

declare(strict_types=1);

namespace Capell\Admin\Macros\Filament;

use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Contracts\Pageable;
use Closure;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @mixin Column
 */
class ColumnMacros
{
    /**
     * Link to a record
     *
     * @credit Dennis Koch - https://filamentphp.com/tricks/link-to-related-model-from-table
     *
     * @return Closure(): Column
     *
     * @return-closure-this Column
     */
    public function linkRecord(): Closure
    {
        return fn (): Column => $this->url(function (TextColumn $column, mixed $record, mixed $state): ?string {
            if ($state instanceof Pageable) {
                return GetEditPageResourceUrlAction::run($state);
            }

            $relationship = Str::before($this->getName(), '.');

            if (! $record instanceof Model) {
                return null;
            }

            $relatedRecord = $record->getRelationValue($relationship);

            if ($relatedRecord === null) {
                return null;
            }

            if ($relatedRecord instanceof Pageable) {
                return GetEditPageResourceUrlAction::run($relatedRecord);
            }

            foreach (Filament::getResources() as $resource) {
                if ($relatedRecord instanceof ($resource::getModel())) {
                    $pages = $resource::getPages();
                    if ($pages['edit'] ?? false) {
                        return $resource::getUrl('edit', ['record' => $relatedRecord]);
                    }

                    if ($pages['index'] ?? false) {
                        return $resource::getUrl(parameters: [
                            'tableAction' => EditAction::getDefaultName(),
                            'tableActionRecord' => $relatedRecord,
                        ]);
                    }
                }
            }

            return null;
        });
    }
}
