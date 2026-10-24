<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Media;

use Filament\Forms\Components\BaseFileUpload;
use Illuminate\Database\Eloquent\Model;

final class AdminUploadOwnership
{
    private const string SESSION_KEY = 'capell.admin_upload_ownership';

    /**
     * Called only with paths produced by validated temporary file storage.
     *
     * @param  list<string>  $paths
     */
    public function remember(BaseFileUpload $upload, array $paths): void
    {
        $ownership = $this->ownership();
        $key = $this->key($upload);
        foreach ($paths as $path) {
            $ownership[$key][$path] = now()->getTimestamp();
        }

        session()->put(self::SESSION_KEY, $ownership);
    }

    /** @return list<string> */
    public function paths(BaseFileUpload $upload): array
    {
        $ownership = $this->ownership();
        $key = $this->key($upload);
        $paths = [];
        foreach (array_keys($ownership[$key] ?? []) as $pathKey) {
            // PHP normalises numeric filename keys to integers. File state
            // and strict path authorisation must still receive strings.
            $path = (string) $pathKey;
            // A deleted/expired stored file must never remain a retry grant.
            if (! $upload->getDisk()->exists($path)) {
                unset($ownership[$key][$pathKey]);

                continue;
            }

            $paths[] = $path;
        }

        session()->put(self::SESSION_KEY, $ownership);

        return $paths;
    }

    private function key(BaseFileUpload $upload): string
    {
        $record = $upload->getRecord();
        $livewire = $upload->getLivewire();

        // Session storage is server-held. Bind grants to the actor, Livewire
        // form instance, record, field and disk rather than submitted state.
        return hash('sha256', serialize([
            auth()->id(),
            $livewire::class,
            $livewire->getId() ?? spl_object_id($livewire),
            $record instanceof Model ? [$record->getMorphClass(), $record->getKey()] : $record,
            $upload->getStatePath(),
            $upload->getDiskName(),
            $upload->getDirectory(),
        ]));
    }

    /** @return array<string, array<array-key, int>> */
    private function ownership(): array
    {
        /** @var array<string, array<array-key, int>> $ownership */
        $ownership = session()->get(self::SESSION_KEY, []);
        $oldest = now()->subMinutes((int) config('session.lifetime', 120))->getTimestamp();
        foreach ($ownership as $key => $paths) {
            $ownership[$key] = array_filter($paths, static fn (int $storedAt): bool => $storedAt > $oldest);
            if ($ownership[$key] === []) {
                unset($ownership[$key]);
            }
        }

        return $ownership;
    }
}
