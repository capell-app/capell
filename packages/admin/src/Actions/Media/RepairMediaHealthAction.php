<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Media;

use Capell\Admin\Data\Media\MediaHealthRepairResultData;
use Capell\Admin\Enums\MediaHealthRepairEnum;
use Capell\Admin\Support\MediaScope;
use Capell\Core\Models\Media;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RepairMediaHealthAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Collection<int, Media>  $selectedMedia
     */
    public function handle(
        Collection $selectedMedia,
        Authenticatable $actor,
        MediaHealthRepairEnum $repair,
    ): MediaHealthRepairResultData {
        $selectedIds = $selectedMedia->modelKeys();

        /** @var Collection<int, Media> $media */
        $media = MediaScope::applyForCurrentActor(
            Media::query()->with(['translations.language']),
        )
            ->whereKey($selectedIds)
            ->get()
            ->keyBy(fn (Media $record): int => (int) $record->getKey());

        $repaired = 0;
        $skipped = [];

        foreach ($selectedIds as $selectedId) {
            $record = $media->get((int) $selectedId);

            if (! $record instanceof Media) {
                $skipped[] = ['id' => (int) $selectedId, 'reason' => 'inaccessible'];
                continue;
            }

            $permission = $repair === MediaHealthRepairEnum::DeleteUnused ? 'delete' : 'update';

            if (! Gate::forUser($actor)->allows($permission, $record)) {
                $skipped[] = ['id' => (int) $selectedId, 'reason' => 'unauthorized'];
                continue;
            }

            $result = match ($repair) {
                MediaHealthRepairEnum::MarkDecorative => $this->markDecorative($record),
                MediaHealthRepairEnum::DeleteUnused => $this->deleteUnused($record),
            };

            if ($result === null) {
                $repaired++;
                continue;
            }

            $skipped[] = ['id' => (int) $selectedId, 'reason' => $result];
        }

        return new MediaHealthRepairResultData(
            repaired: $repaired,
            skipped: $skipped,
        );
    }

    private function markDecorative(Media $media): ?string
    {
        $state = BuildMediaHealthStateAction::run($media);

        if (! $state->missingAlt) {
            return 'not_missing_alt';
        }

        if (! $media->isImage()) {
            return 'not_an_image';
        }

        $metadata = $media->localizedMetadata();

        if ($metadata->languageId === null) {
            return 'missing_language';
        }

        $translation = $media->translations->firstWhere('language_id', $metadata->languageId);

        if ($translation === null) {
            return 'missing_translation';
        }

        $meta = is_array($translation->meta) ? $translation->meta : [];
        unset($meta['alt']);
        $meta['decorative'] = true;

        $translation->forceFill(['meta' => $meta])->save();

        return null;
    }

    private function deleteUnused(Media $media): ?string
    {
        if (! BuildMediaHealthStateAction::run($media)->unused) {
            return 'in_use';
        }

        return $media->delete() ? null : 'delete_failed';
    }
}
