<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Media;

use Filament\Forms\Components\BaseFileUpload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

final class AdminUploadOwnership
{
    /**
     * Called only with paths produced by validated temporary file storage.
     *
     * @param  list<string>  $paths
     */
    public function remember(BaseFileUpload $upload, array $paths): void
    {
        // HTTP middleware normally starts the session. Also support server-side
        // form entry points without creating a namespace during read-only checks.
        if (! session()->isStarted()) {
            session()->start();
        }

        $scope = $this->scope($upload);
        if ($scope === null) {
            return;
        }

        foreach ($paths as $path) {
            // One atomic insert per grant: no session snapshot or shared index
            // can erase another request's uploads. add() never renews a grant.
            Cache::add($this->key($scope, $path), true, now()->addMinutes((int) config('session.lifetime', 120)));
        }
    }

    /** @return list<string> */
    public function paths(BaseFileUpload $upload): array
    {
        $scope = $this->scope($upload);
        if ($scope === null) {
            return [];
        }

        // Submitted strings are candidates only, never authority to mint grants.
        // No enumerable index, session write, cleanup or TTL refresh on reads.
        return array_values(array_filter(
            $this->candidates($upload),
            fn (string $path): bool => Cache::get($this->key($scope, $path)) === true && $upload->getDisk()->exists($path),
        ));
    }

    public function forgetMissingPaths(BaseFileUpload $upload): void
    {
        $scope = $this->scope($upload);
        if ($scope === null) {
            return;
        }

        foreach ($this->candidates($upload) as $path) {
            $key = $this->key($scope, $path);
            if (Cache::get($key) === true && ! $upload->getDisk()->exists($path)) {
                Cache::forget($key);
            }
        }
    }

    private function scope(BaseFileUpload $upload): ?string
    {
        $builderTypes = resolve(AdminUploadBuilderTypes::class)->forUpload($upload);
        if ($builderTypes === null) {
            return null;
        }

        $record = $upload->getRecord();
        $livewire = $upload->getLivewire();

        // Session regeneration and actor changes invalidate old grants. Include
        // the CSRF token so flushing a session also revokes its previous grants.
        return hash('sha256', serialize([
            session()->getId(),
            session()->token(),
            auth()->id(),
            $livewire::class,
            $livewire->getId() ?? spl_object_id($livewire),
            $record instanceof Model ? [$record->getMorphClass(), $record->getKey()] : $record,
            $upload->getStatePath(),
            $builderTypes,
            $upload->getDiskName(),
            $upload->getDirectory(),
        ]));
    }

    private function key(string $scope, string $path): string
    {
        return 'capell:admin-upload:' . $scope . ':' . hash('sha256', $path);
    }

    /** @return list<string> */
    private function candidates(BaseFileUpload $upload): array
    {
        return array_values(array_unique(array_filter(
            Arr::wrap($upload->getRawState()),
            static fn (mixed $path): bool => is_string($path) && $path !== '',
        )));
    }
}
