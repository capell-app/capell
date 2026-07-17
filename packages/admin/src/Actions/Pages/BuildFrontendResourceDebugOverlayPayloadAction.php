<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Core\Models\Page;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class BuildFrontendResourceDebugOverlayPayloadAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array<string, mixed>
     */
    public function handle(Page $page): array
    {
        $payload = app()->bound('capell.frontend.resource-debug-overlay-payload')
            ? resolve('capell.frontend.resource-debug-overlay-payload')
            : null;

        if (! is_callable($payload)) {
            return [];
        }

        $result = $payload($page);

        return is_array($result) ? $result : [];
    }
}
