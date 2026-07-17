<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Core\Models\Page;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class BuildPageFrontendResourceDiagnosticsAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array<string, mixed>
     */
    public function handle(Page $page): array
    {
        $diagnostics = app()->bound('capell.frontend.page-resource-diagnostics')
            ? resolve('capell.frontend.page-resource-diagnostics')
            : null;

        if (! is_callable($diagnostics)) {
            return [];
        }

        $result = $diagnostics($page);

        return is_array($result) ? $result : [];
    }
}
