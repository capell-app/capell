<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Outcome of asking for a frontend asset build.
 *
 * A build that was not queued has two very different causes, and reporting both
 * as "already running" leaves an operator unable to tell a real in-progress build
 * from a momentary lock collision with an unrelated request.
 */
enum FrontendBuildQueueResultEnum: string implements HasLabel
{
    case Queued = 'queued';
    case AlreadyRunning = 'already_running';
    case Contended = 'contended';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => (string) __('capell-admin::message.frontend_build_queued'),
            self::AlreadyRunning => (string) __('capell-admin::message.frontend_build_already_running'),
            self::Contended => (string) __('capell-admin::message.frontend_build_contended'),
        };
    }

    public function queued(): bool
    {
        return $this === self::Queued;
    }
}
