<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Extensions\Fixtures;

final class ExtensionOperationsSummaryInaccessiblePage
{
    public static function getUrl(): string
    {
        return '/admin/hidden-extension';
    }

    public static function canAccess(): bool
    {
        return false;
    }
}
