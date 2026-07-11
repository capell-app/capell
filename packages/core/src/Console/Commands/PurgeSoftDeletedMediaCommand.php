<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Models\Media;
use Illuminate\Console\Command;

/**
 * Reclaims storage by force-deleting Media rows that have been soft-deleted
 * for longer than the retention window (default 30 days).
 *
 * Scheduled by the admin package's content-retention schedule. Pass
 * --pretend to log what would be purged without touching disk.
 */
final class PurgeSoftDeletedMediaCommand extends Command
{
    protected $signature = 'capell:purge-soft-deleted-media
        {--days=30 : Retention window in days}
        {--pretend : Report rows that would be purged without deleting}';

    protected $description = 'Force-delete media that has been soft-deleted longer than the retention window.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $pretend = (bool) $this->option('pretend');

        $cutoff = now()->subDays($days);

        $query = Media::onlyTrashed()->where('deleted_at', '<=', $cutoff);
        $count = $query->count();

        if ($count === 0) {
            $this->info(sprintf('No media older than %d day(s) to purge.', $days));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d media row(s) soft-deleted before %s.',
            $pretend ? 'Would purge' : 'Purging',
            $count,
            $cutoff->toDateTimeString(),
        ));

        if ($pretend) {
            $query->each(function (Media $media): void {
                $this->line(sprintf('  - DRY #%d %s (deleted_at=%s)', $media->id, $media->file_name, $media->deleted_at));
            });

            return self::SUCCESS;
        }

        $query->each(function (Media $media): void {
            $media->forceDelete();
        });

        $this->info(sprintf('Purged %d media row(s).', $count));

        return self::SUCCESS;
    }
}
