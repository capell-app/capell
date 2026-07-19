<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\PublishVisibilityActionResultData;
use Capell\Core\Actions\PageSavedAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Page;
use Capell\Core\Support\Publishing\PublicationDateGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class UnpublishPageAction
{
    use AsFake;
    use AsObject;

    public function handle(Page&Pageable $page, User $actor): PublishVisibilityActionResultData
    {
        $response = Gate::forUser($actor)->inspect('update', $page);

        if (! $response->allowed()) {
            return PublishVisibilityActionResultData::skipped('unauthorized');
        }

        if ($page->isExpired() || $page->isPending()) {
            return PublishVisibilityActionResultData::skipped('not_live');
        }

        $unpublishedAt = CarbonImmutable::now();

        PublicationDateGuard::allow(function () use ($page, $unpublishedAt): void {
            $page->visible_until = $unpublishedAt;
            $page->save();
        });

        PageSavedAction::run($page, [
            'visible_until' => $unpublishedAt->toDateTimeString(),
            'unpublished_by' => $actor->getKey(),
        ]);

        return PublishVisibilityActionResultData::changed();
    }
}
