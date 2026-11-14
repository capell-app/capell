<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\MediaLibrary\HasMedia;

/**
 * Media used by a translation's content now belongs to the translated record, so one item serves every language.
 * Each translation-owned media row moves to its translatable owner. A row that is an exact copy of an item the owner
 * already holds in the same collection (same file name, size and type, as copying content between languages
 * produced) is merged instead: references to its uuid in the owner's translations point at the kept item, its
 * per-language alt text moves across when the kept item has none for that language, and the copy is soft-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mediaTable = TableName::resolve('media');
        $translationsTable = TableName::resolve('translations');

        if (! Schema::hasTable($mediaTable) || ! Schema::hasTable($translationsTable)) {
            return;
        }

        // Morph aliases, not imports: a migration must keep working whatever the models later become.
        $translationTypes = $this->morphTypes('translation');
        $mediaTypes = $this->morphTypes('media');
        /** @var array<string, array{type: string, id: int, rows: list<array{id: int, uuid: string, fingerprint: string}>}> $owners */
        $owners = [];

        $rows = DB::table($mediaTable . ' as media')
            ->join($translationsTable . ' as translation', 'translation.id', '=', 'media.model_id')
            ->whereIn('media.model_type', $translationTypes)
            ->whereNull('media.deleted_at')
            ->orderBy('media.id')
            ->get([
                'media.id', 'media.uuid', 'media.collection_name', 'media.file_name', 'media.size', 'media.mime_type',
                'translation.translatable_type', 'translation.translatable_id',
            ]);

        foreach ($rows as $row) {
            $media = $this->mediaRow($row);
            $ownerType = data_get($row, 'translatable_type');
            $ownerId = data_get($row, 'translatable_id');
            if ($media === null) {
                continue;
            }

            if (! is_string($ownerType)) {
                continue;
            }

            if (! is_numeric($ownerId)) {
                continue;
            }

            if (! $this->holdsMedia($ownerType)) {
                continue;
            }

            $owners[$ownerType . ':' . $ownerId] ??= ['type' => $ownerType, 'id' => (int) $ownerId, 'rows' => []];
            $owners[$ownerType . ':' . $ownerId]['rows'][] = $media;
        }

        foreach ($owners as $owner) {
            DB::transaction(fn () => $this->moveToOwner($owner, $mediaTable, $translationsTable, $mediaTypes));
        }
    }

    public function down(): void
    {
        // Moved media stays with its owner: the translations that used it still reference it there.
    }

    /**
     * @param  array{type: string, id: int, rows: list<array{id: int, uuid: string, fingerprint: string}>}  $owner
     * @param  list<string>  $mediaTypes
     */
    private function moveToOwner(array $owner, string $mediaTable, string $translationsTable, array $mediaTypes): void
    {
        $ownerTypes = array_values(array_unique([$owner['type'], Relation::getMorphedModel($owner['type']) ?? $owner['type']]));
        /** @var array<string, array{id: int, uuid: string, fingerprint: string}> $kept */
        $kept = [];
        $existing = DB::table($mediaTable)
            ->whereIn('model_type', $ownerTypes)
            ->where('model_id', $owner['id'])
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'uuid', 'collection_name', 'file_name', 'size', 'mime_type']);

        foreach ($existing as $row) {
            $media = $this->mediaRow($row);

            if ($media !== null) {
                $kept[$media['fingerprint']] ??= $media;
            }
        }

        foreach ($owner['rows'] as $media) {
            $original = $kept[$media['fingerprint']] ?? null;

            if ($original === null) {
                DB::table($mediaTable)->where('id', $media['id'])->update(['model_type' => $owner['type'], 'model_id' => $owner['id']]);
                $kept[$media['fingerprint']] = $media;

                continue;
            }

            $this->repointReferences($translationsTable, $owner['type'], $owner['id'], $media['uuid'], $original['uuid']);
            $this->moveAltText($translationsTable, $mediaTypes, $media['id'], $original['id']);
            DB::table($mediaTable)->where('id', $media['id'])->update(['deleted_at' => now()]);
        }
    }

    /** @return list<string> */
    private function morphTypes(string $alias): array
    {
        $class = Relation::getMorphedModel($alias);

        return array_values(array_unique(is_string($class) ? [$alias, $class] : [$alias]));
    }

    private function holdsMedia(string $type): bool
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        return class_exists($class) && is_subclass_of($class, HasMedia::class);
    }

    /**
     * @return array{id: int, uuid: string, fingerprint: string}|null
     */
    private function mediaRow(object $row): ?array
    {
        $id = data_get($row, 'id');
        $uuid = data_get($row, 'uuid');

        if (! is_numeric($id) || ! is_string($uuid) || $uuid === '') {
            return null;
        }

        $parts = array_map(
            static fn (string $column): string => is_scalar($value = data_get($row, $column)) ? (string) $value : '',
            ['collection_name', 'file_name', 'size', 'mime_type'],
        );

        return ['id' => (int) $id, 'uuid' => $uuid, 'fingerprint' => implode('|', $parts)];
    }

    private function repointReferences(string $translationsTable, string $ownerType, int $ownerId, string $from, string $to): void
    {
        $translations = DB::table($translationsTable)
            ->where('translatable_type', $ownerType)
            ->where('translatable_id', $ownerId)
            ->get(['id', 'content', 'meta']);

        foreach ($translations as $translation) {
            $changes = [];

            foreach (['content', 'meta'] as $column) {
                $value = data_get($translation, $column);

                if (is_string($value) && str_contains($value, $from)) {
                    $changes[$column] = str_replace($from, $to, $value);
                }
            }

            if ($changes !== []) {
                DB::table($translationsTable)->where('id', data_get($translation, 'id'))->update($changes);
            }
        }
    }

    /** @param  list<string>  $mediaTypes */
    private function moveAltText(string $translationsTable, array $mediaTypes, int $from, int $to): void
    {
        $covered = DB::table($translationsTable)->whereIn('translatable_type', $mediaTypes)->where('translatable_id', $to)->whereNull('deleted_at')->pluck('language_id')->all();

        DB::table($translationsTable)
            ->whereIn('translatable_type', $mediaTypes)
            ->where('translatable_id', $from)
            ->whereNull('deleted_at')
            ->whereNotIn('language_id', $covered)
            ->update(['translatable_id' => $to]);
    }
};
