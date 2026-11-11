<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Actions\Publishing\TransitionPublicationAction;
use Capell\Core\Data\Install\PublishInstalledSiteResultData;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Enums\Publishing\PublicationTransitionOutcome;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * Publishes the draft pages of the site the installer just created, so `--publish` ends with a
 * site that is live. Pages already published and pages with a real publish schedule are left as
 * they are, which makes a rerun after a stopped install safe: it publishes whatever is still draft
 * and does nothing else.
 */
final class PublishInstalledSiteAction
{
    use AsFake;
    use AsObject;

    /** @throws RuntimeException when a draft page cannot be published, naming each one */
    public function handle(Authenticatable $actor): PublishInstalledSiteResultData
    {
        $site = $this->resolveSite();

        if (! $site instanceof Site) {
            return new PublishInstalledSiteResultData(published: 0);
        }

        $now = CarbonImmutable::now();
        $published = 0;
        $refused = [];

        foreach (Page::query()->where('site_id', $site->getKey())->draft()->orderBy('id')->get() as $page) {
            $result = TransitionPublicationAction::run(new PublicationTransitionRequestData(
                record: $page,
                transition: PublicationTransition::PublishNow,
                actor: $actor,
                now: $now,
            ));

            match ($result->outcome) {
                PublicationTransitionOutcome::Changed => $published++,
                PublicationTransitionOutcome::AlreadyCorrect => null,
                default => $refused[] = (string) $page->name,
            };
        }

        throw_if($refused !== [], RuntimeException::class, (string) __('capell-core::install.publish.refused', [
            'pages' => implode(', ', $refused),
        ]));

        return new PublishInstalledSiteResultData(published: $published, refused: $refused);
    }

    private function resolveSite(): ?Site
    {
        if (! Schema::hasTable((new Site)->getTable())) {
            return null;
        }

        return Site::query()->where('default', true)->first() ?? Site::query()->first();
    }
}
