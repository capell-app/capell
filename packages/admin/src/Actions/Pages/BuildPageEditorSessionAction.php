<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\PageEditorSessionData;
use Capell\Core\Models\Page;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPageEditorSessionAction
{
    use AsFake;
    use AsObject;

    public function handle(
        Page $page,
        ?Authenticatable $user,
        string $locale,
        string $heartbeatUrl,
        string $releaseUrl,
        string $logoutUrl,
        ?string $csrfToken,
        bool $initialConflict,
    ): PageEditorSessionData {
        $userId = $user?->getAuthIdentifier();
        $userKey = is_scalar($userId) ? (string) $userId : 'anonymous';

        return new PageEditorSessionData(
            heartbeatUrl: $heartbeatUrl,
            releaseUrl: $releaseUrl,
            logoutUrl: $logoutUrl,
            csrfToken: $csrfToken,
            initialConflict: $initialConflict,
            pageId: (int) $page->getKey(),
            storageKey: sprintf(
                'capell:page-editor:%s:%s:%s',
                $userKey,
                (string) $page->getKey(),
                $locale,
            ),
        );
    }
}
