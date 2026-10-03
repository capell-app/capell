<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Actions\ContentGraph\ValidateContentDeleteImpactAction;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Admin\Support\ContentGraph\SharedDeleteImpact;
use Capell\Core\Actions\HasRetainedDeletionDependenciesAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ValidateForceDeleteAction
{
    use AsFake;
    use AsObject;

    public function handle(Model $record, ?ValidatesDelete $validator): bool
    {
        return resolve(SharedDeleteImpact::class)->during($record, fn (): bool => $this->validateRecord($record, $validator));
    }

    private function validateRecord(Model $record, ?ValidatesDelete $validator): bool
    {
        Gate::authorize('forceDelete', $record);

        if ($record instanceof Page && resolve(HasRetainedDeletionDependenciesAction::class)->hasPageDescendants($record)) {
            Notification::make('page_descendants_not_deletable')
                ->warning()
                ->title(__('capell-admin::message.page_not_deletable'))
                ->body(__('capell-admin::message.page_descendants_not_deletable_info'))
                ->send();

            return false;
        }

        if ($validator instanceof ValidatesDelete && ! $validator->validateDelete($record)) {
            return false;
        }

        throw_if(! $validator instanceof ValidatesDelete && ($record instanceof Page || $record instanceof Layout || $record instanceof Theme || $record instanceof Blueprint || $record instanceof Language), LogicException::class, 'This resource must implement ValidatesDelete before offering permanent deletion.');

        if (HasRetainedDeletionDependenciesAction::run($record)) {
            Notification::make('retained_dependencies_not_deletable')
                ->warning()
                ->title(__($record instanceof Site ? 'capell-admin::message.site_force_delete_blocked' : 'capell-admin::message.content_graph_delete_blocked'))
                ->body(__($record instanceof Site ? 'capell-admin::message.site_force_delete_blocked_info' : 'capell-admin::message.force_delete_dependencies_info'))
                ->send();

            return false;
        }

        $impact = ValidateContentDeleteImpactAction::run($record);

        if (! $impact->allowed) {
            Notification::make('record_graph_dependencies_not_deletable')
                ->warning()
                ->title(__('capell-admin::message.content_graph_delete_blocked'))
                ->body(__('capell-admin::message.content_graph_delete_blocked_info', ['count' => $impact->blockingCount]))
                ->send();

            return false;
        }

        return true;
    }
}
