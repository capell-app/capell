<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Icons\Heroicon;

/**
 * Presentation-only classification of restoring a history row relative to the
 * page's active content version. The append-only engine restores to any version
 * identically; only the framing differs:
 *
 *  - Back    — the row is older than live content → an undo.
 *  - Forward — the row is newer than live content → a redo of undone content.
 *  - Current — the row already is the live content → restoring is a no-op.
 *
 * Centralising the icon/color/label mapping here keeps the relation manager's
 * column and action in sync and keys every branch off one backed enum rather
 * than loose strings.
 */
enum RollbackDirection: string
{
    case Back = 'back';
    case Forward = 'forward';
    case Current = 'current';

    public static function forVersions(int $rowVersion, int $activeContentVersion): self
    {
        return match (true) {
            $rowVersion < $activeContentVersion => self::Back,
            $rowVersion > $activeContentVersion => self::Forward,
            default => self::Current,
        };
    }

    public function icon(): Heroicon
    {
        return $this === self::Forward
            ? Heroicon::ArrowUturnRight
            : Heroicon::ArrowUturnLeft;
    }

    /**
     * Semantic Filament token: neutral for undo, primary for redo. Restoring is
     * append-only and reversible, so back needs no caution color — amber is
     * reserved for genuinely cautionary states (e.g. blocked restores).
     */
    public function color(): string
    {
        return $this === self::Forward ? 'primary' : 'gray';
    }

    public function actionLabelKey(): string
    {
        return $this === self::Forward
            ? 'capell-admin::event-sourcing.roll_forward_action'
            : 'capell-admin::event-sourcing.rollback_action';
    }

    public function headingKey(): string
    {
        return $this === self::Forward
            ? 'capell-admin::event-sourcing.roll_forward_heading'
            : 'capell-admin::event-sourcing.rollback_heading';
    }

    public function confirmKey(): string
    {
        return $this === self::Forward
            ? 'capell-admin::event-sourcing.roll_forward_confirm'
            : 'capell-admin::event-sourcing.rollback_confirm';
    }

    public function helpKey(): string
    {
        return $this === self::Forward
            ? 'capell-admin::event-sourcing.restore_help_forward'
            : 'capell-admin::event-sourcing.restore_help_back';
    }

    public function summaryKey(): string
    {
        return $this === self::Forward
            ? 'capell-admin::event-sourcing.roll_forward_summary'
            : 'capell-admin::event-sourcing.rollback_summary';
    }

    public function doneKey(): string
    {
        return $this === self::Forward
            ? 'capell-admin::event-sourcing.roll_forward_done'
            : 'capell-admin::event-sourcing.rollback_done';
    }

    public function targetColumnKey(): string
    {
        return $this === self::Forward
            ? 'capell-admin::event-sourcing.rollback_target_forward'
            : 'capell-admin::event-sourcing.rollback_target_back';
    }

    public function previewIntroKey(): string
    {
        return $this === self::Forward
            ? 'capell-admin::event-sourcing.preview_intro_forward'
            : 'capell-admin::event-sourcing.preview_intro_back';
    }
}
