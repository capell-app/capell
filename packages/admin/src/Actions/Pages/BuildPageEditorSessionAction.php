<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\PageEditorSessionData;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Page;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPageEditorSessionAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Model&Pageable<Model>  $page
     */
    public function handle(
        Model&Pageable $page,
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
        $recordKey = $page->getMorphClass() === (new Page)->getMorphClass()
            ? (string) $page->getKey()
            : $page->getMorphClass() . ':' . $page->getKey();

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
                $recordKey,
                $locale,
            ),
        );
    }
}
