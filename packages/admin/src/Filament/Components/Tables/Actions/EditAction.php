<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Actions;

use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Override;

class EditAction extends \Filament\Actions\EditAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->iconButton()
            ->tooltip(function (self $action): string {
                $label = $action->getLabel();

                return $label instanceof Htmlable ? $label->toHtml() : (string) $label;
            })
            ->after(function (Page $livewire, ?Model $record): void {
                if (! $record instanceof Model) {
                    return;
                }

                if (method_exists($livewire, 'notifyPageCached')) {
                    $livewire->notifyPageCached($record);
                }
            });
    }
}
