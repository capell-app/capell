<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Media;

use Capell\Admin\Actions\Media\PruneAdminUploadGrantsAction;
use Closure;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

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

        PruneAdminUploadGrantsAction::run(100);
        $registry = resolve(AdminUploadGrantRepository::class);
        foreach ($paths as $path) {
            $registry->grant($scope, $path);
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
            resolve(AdminUploadGrantRepository::class)->paths($scope, $this->candidates($upload)),
            fn (string $path): bool => $upload->getDisk()->exists($path),
        ));
    }

    public function forgetMissingPaths(BaseFileUpload $upload): void
    {
        $scope = $this->scope($upload);
        if ($scope === null) {
            return;
        }

        $registry = resolve(AdminUploadGrantRepository::class);
        foreach ($registry->paths($scope, $this->candidates($upload)) as $path) {
            if (! $upload->getDisk()->exists($path)) {
                $registry->forget($scope, $path);
            }
        }
    }

    /** @return Closure(): void */
    public function captureRevocation(BaseFileUpload $upload, string $path): Closure
    {
        $scope = $this->scope($upload);

        return static function () use ($scope, $path): void {
            if ($scope !== null) {
                resolve(AdminUploadGrantRepository::class)->forget($scope, $path);
            }
        };
    }

    private function scope(BaseFileUpload $upload): ?string
    {
        $builderTypes = resolve(AdminUploadBuilderTypes::class)->forUpload($upload);
        if ($builderTypes === null) {
            return null;
        }

        $records = [];
        $record = $upload->getRecord();
        $records[] = $record instanceof Model ? [$record->getMorphClass(), $record->getKey()] : $record;
        $container = $upload->getContainer();
        // Walk actual ancestry, never getModelRootContainer(): local model([])
        // and relationship models must not hide the enclosing form's record.
        while (true) {
            $record = $container->getRecord();
            $records[] = $record instanceof Model ? [$record->getMorphClass(), $record->getKey()] : $record;
            $parent = $container->getParentComponent();
            if (! $parent instanceof Component) {
                break;
            }

            $container = $parent->getContainer();
        }

        $livewire = $upload->getLivewire();

        // Session regeneration and actor changes invalidate old grants. Include
        // the CSRF token so flushing a session also revokes its previous grants.
        return hash('sha256', serialize([
            session()->getId(),
            session()->token(),
            auth()->id(),
            $livewire::class,
            $livewire->getId() ?? spl_object_id($livewire),
            $records,
            $upload->getStatePath(),
            $builderTypes,
            $upload->getDiskName(),
            $upload->getDirectory(),
        ]));
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
