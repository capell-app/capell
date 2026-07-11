<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Redirects;

use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\PageUrl;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class RefreshRedirectHealthSnapshotsAction
{
    use AsAction;

    /**
     * @return array{refreshed: int}
     */
    public function handle(int $chunkSize = 100): array
    {
        $refreshed = 0;

        PageUrl::query()
            ->where('type', UrlTypeEnum::Redirect)
            ->where('status', true)
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
