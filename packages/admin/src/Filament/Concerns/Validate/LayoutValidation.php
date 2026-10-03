<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns\Validate;

use Capell\Admin\Actions\ContentGraph\ValidateContentDeleteImpactAction;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Core\Models\Layout;
use Capell\Core\Support\Permissions\SiteAccess;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * @phpstan-require-implements ValidatesDelete
 */
trait LayoutValidation
{
    /**
     * @param  Layout  $record
     */
    public function validateDelete(Model $record): bool
    {
        // The global existence check protects integrity; only the scoped count
        // belongs in the notification shown to an author.
        if ($record->pages()->exists()) {
            Notification::make('layout_not_deletable')
                ->warning()
                ->title(__(
                    'capell-admin::message.layout_not_deletable',
                    ['name' => $record->name],
                ))
                ->body(__(
                    'capell-admin::message.layout_not_deletable_info',
                    ['count' => SiteAccess::current()->scope($record->pages()->getQuery())->count()],
                ))
                ->actions([
                    Action::make('pages')
                        ->label(__('capell-admin::button.view_pages'))
                        ->button()
                        ->url(PageResource::getUrl(
                            'index',
                            ['filters[layout_id][value]' => $record->id],
                        )),
                ])
                ->send();

            return false;
        }

        $deleteImpact = ValidateContentDeleteImpactAction::run($record);

        if (! $deleteImpact->allowed) {
            Notification::make('layout_graph_dependencies_not_deletable')
                ->warning()
                ->title(__('capell-admin::message.content_graph_delete_blocked'))
                ->body(__('capell-admin::message.content_graph_delete_blocked_info', [
                    'count' => $deleteImpact->blockingCount,
                ]))
                ->send();

            return false;
        }

        return true;
    }
}
