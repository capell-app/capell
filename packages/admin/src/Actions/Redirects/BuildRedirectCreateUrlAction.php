<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Redirects;

use Capell\Admin\Filament\Resources\Redirects\RedirectResource;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Lorisleiva\Actions\Concerns\AsAction;

class BuildRedirectCreateUrlAction
{
    use AsAction;

    public function handle(
        ?string $sourceUrl = null,
        ?string $targetUrl = null,
        ?int $siteId = null,
        ?int $languageId = null,
        RedirectStatusCodeEnum $statusCode = RedirectStatusCodeEnum::Permanent,
    ): string {
        return RedirectResource::getUrl('index', [
            'create_redirect' => 1,
            'url' => $sourceUrl,
            'target_url' => $targetUrl,
            'site_id' => $siteId,
            'language_id' => $languageId,
            'status_code' => $statusCode->value,
        ]);
    }
}
