<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

final class WorkFirstScopeBoundary
{
    public static function applies(object $widget): bool
    {
        return method_exists($widget, 'rejectInvalidWorkFirstScope');
    }
}
