<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns\Validate;

use Capell\Admin\Actions\ContentGraph\ValidateContentDeleteImpactAction;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Core\Actions\GetResourceFromBlueprintAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * @phpstan-require-implements ValidatesDelete
 */
trait PageValidation
{
    /**
     * @param  Page  $record
     */
    public function validateDelete(Model $record): bool
    {
        if (! $record instanceof Page) {
            return true;
        }

        $type = $record->type;

        if (! $type instanceof Blueprint) {
            return true;
        }

        if (isset($type->admin['deletable']) && $type->admin['deletable'] === false) {
            Notification::make('page_not_deletable')
                ->warning()
                ->title(__(
                    'capell-admin::message.page_not_deletable',
                    ['name' => $record->name],
                ))
                ->body(__(
                    'capell-admin::message.page_page_type_not_deletable',
                    ['name' => $type->name],
                ))
                ->send();

            return false;
        }

        if ($record->canonicalPages()->exists()) {
            Notification::make('page_not_deletable')
                ->warning()
                ->title(__(
                    'capell-admin::message.page_not_deletable',
                    ['name' => $record->name],
                ))
                ->body(__(
                    'capell-admin::message.canonical_page_not_deletable',
                    ['count' => $record->canonicalPages()->count()],
                ))
                ->actions([
                    Action::make('pages')
                        ->label(__('capell-admin::button.view_pages'))
                        ->button()
                        ->url(function () use ($record, $type): ?string {
                            $resource = GetResourceFromBlueprintAction::run(ResourceEnum::Page, $type);

                            if ($resource === null) {
                                return null;
                            }

                            return $resource::getUrl(
                                'index',
                                ['filters[filter][canonical_page_id]' => $record->id],
                            );
                        }),
                ])
                ->send();

            return false;
        }

        $deleteImpact = ValidateContentDeleteImpactAction::run($record);

        if (! $deleteImpact->allowed) {
            Notification::make('page_graph_dependencies_not_deletable')
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
