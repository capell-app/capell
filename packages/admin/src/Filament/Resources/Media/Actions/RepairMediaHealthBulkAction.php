<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Media\Actions;

use Capell\Admin\Actions\Media\RepairMediaHealthAction;
use Capell\Admin\Enums\MediaHealthRepairEnum;
use Capell\Core\Models\Media;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;

final class RepairMediaHealthBulkAction
{
    public static function markDecorative(): BulkAction
    {
        return self::make(
            name: 'mark-media-decorative',
            repair: MediaHealthRepairEnum::MarkDecorative,
            label: __('capell-admin::bulk_actions.media_mark_decorative'),
            heading: __('capell-admin::bulk_actions.media_mark_decorative_heading'),
            description: __('capell-admin::bulk_actions.media_mark_decorative_description'),
            icon: Heroicon::OutlinedEyeSlash,
            color: 'warning',
        );
    }

    public static function deleteUnused(): BulkAction
    {
        return self::make(
            name: 'delete-unused-media',
            repair: MediaHealthRepairEnum::DeleteUnused,
            label: __('capell-admin::bulk_actions.media_delete_unused'),
            heading: __('capell-admin::bulk_actions.media_delete_unused_heading'),
            description: __('capell-admin::bulk_actions.media_delete_unused_description'),
            icon: Heroicon::OutlinedTrash,
            color: 'danger',
        );
    }

    private static function make(
        string $name,
        MediaHealthRepairEnum $repair,
        string $label,
        string $heading,
        string $description,
        Heroicon $icon,
        string $color,
    ): BulkAction {
        return BulkAction::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->modalHeading($heading)
            ->modalDescription(fn (Collection $records): string => $description . ' ' . __('capell-admin::bulk_actions.media_selected_count', [
                'count' => $records->count(),
            ]))
            ->action(function (Collection $records) use ($repair): void {
                /** @var User $actor */
                $actor = auth()->user();

                $result = RepairMediaHealthAction::run(
                    selectedMedia: $records->filter(fn (mixed $record): bool => $record instanceof Media),
                    actor: $actor,
                    repair: $repair,
                );

                $notification = Notification::make()
                    ->title(__('capell-admin::bulk_actions.media_repair_done', [
                        'repaired' => $result->repaired,
                        'skipped' => $result->skippedCount(),
                    ]));

                if ($result->skipped !== []) {
                    $notification->body(implode("\n", array_map(
                        fn (array $row): string => sprintf(
                            '• #%d — %s',
                            $row['id'],
                            __('capell-admin::bulk_actions.media_repair_reason_' . $row['reason']),
                        ),
                        $result->skipped,
                    )));
                }

                $result->repaired > 0 ? $notification->success() : $notification->warning();
                $notification->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
