<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Redirects;

use Capell\Core\Models\PageUrl;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RefreshRedirectHealthSnapshotsAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array{refreshed: int}
     */
    public function handle(int $chunkSize = 100): array
    {
        $refreshed = 0;

        PageUrl::query()
            ->activeRedirects()
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $redirects) use (&$refreshed): void {
                $redirects->each(function (PageUrl $redirect) use (&$refreshed): void {
                    RefreshRedirectHealthSnapshotAction::run($redirect);
                    $refreshed++;
                });
            });

        return ['refreshed' => $refreshed];
    }
}
