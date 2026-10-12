<?php

declare(strict_types=1);

namespace Capell\Admin\Exceptions;

use RuntimeException;

/**
 * A booted panel received new pages, resources or widgets. Its routes and Livewire
 * components are already built, so only a fresh application can serve the change.
 * This is a deferral of the panel, not a failure of the package that caused it.
 */
final class PanelTopologyRefreshRequiredException extends RuntimeException
{
    public static function make(): self
    {
        return new self(__('capell-admin::message.extension_panel_topology_refresh'));
    }
}
