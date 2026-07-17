<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\PublishVisibilityActionResultData;
use Capell\Core\Actions\PageSavedAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Page;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class CancelScheduledPageUnpublishAction
{
    use AsFake;
    use AsObject;

    public function handle(Page&Pageable $page, User $actor): PublishVisibilityActionResultData
    {
        $response = Gate::forUser($actor)->inspect('update', $page);

        if (! $response->allowed()) {
            return PublishVisibilityActionResultData::skipped('unauthorized');
        }

        if (! $page->visible_until?->isFuture()) {
            return PublishVisibilityActionResultData::skipped('not_scheduled');
        }

        $page->visible_until = null;
        $page->save();

        PageSavedAction::run($page, [
            'visible_until' => null,
            'cancelled_scheduled_unpublish_by' => $actor->getKey(),
        ]);

        return PublishVisibilityActionResultData::changed();
    }
}
