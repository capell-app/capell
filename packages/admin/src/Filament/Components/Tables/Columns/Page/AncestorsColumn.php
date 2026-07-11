<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Columns\Page;

use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Contracts\Pageable;
use Exception;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class AncestorsColumn extends TextColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::table.parents'))
            ->wrap()
            ->html()
            ->disabledClick()
            ->getStateUsing(fn (Model $record): ?Collection => $this->resolvePageRecord($record)->ancestors)
            ->separator('&raquo;')
            ->formatStateUsing(function (?Pageable $state): ?HtmlString {
                if (! $state instanceof Pageable) {
                    return null;
                }

                return new HtmlString(sprintf(
                    "<a href='%s' class='text-gray-500 dark:text-gray-400 hover:text-primary-600 focus:text-primary-600'>%s</a>",
                    GetEditPageResourceUrlAction::run($state),
                    $state->name,
                ));
            });
    }

    /**
     * @return Pageable<Model>
     */
    private function resolvePageRecord(Model $record): Pageable
    {
        if ($record instanceof Pageable) {
            return $record;
        }

        $page = $record->getRelation('page');

        if ($page instanceof Pageable) {
            return $page;
        }

        throw new Exception('Page relation not found.');
    }
}
