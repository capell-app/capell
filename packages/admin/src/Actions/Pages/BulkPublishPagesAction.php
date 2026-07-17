<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Actions\Publishing\RunBulkPublicationTransitionAction;
use Capell\Admin\Data\Publishing\BulkPublicationPreviewData;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BulkPublishPagesAction
{
    use AsFake;
    use AsObject;

    /** @param Collection<int, Page&Pageable> $pages */
    public function handle(Collection $pages, User $actor): BulkPublicationPreviewData
    {
        return RunBulkPublicationTransitionAction::run(
            records: $pages,
            actor: $actor,
            transition: PublicationTransition::PublishNow,
            now: CarbonImmutable::now(),
        );
    }
}
