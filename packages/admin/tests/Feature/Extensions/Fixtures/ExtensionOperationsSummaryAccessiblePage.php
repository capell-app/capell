<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Extensions\Fixtures;

final class ExtensionOperationsSummaryAccessiblePage
{
    /**
     * @param  array<string, string>  $parameters
     */
    public static function getUrl(array $parameters = []): string
    {
        return '/admin/visual-extension?' . http_build_query($parameters);
    }

    public static function canAccess(): bool
    {
        return true;
    }
}
