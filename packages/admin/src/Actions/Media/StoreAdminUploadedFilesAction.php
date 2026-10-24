<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Media;

use Capell\Admin\Support\Media\AdminUploadOwnership;
use Filament\Forms\Components\BaseFileUpload;
use Illuminate\Support\Arr;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class StoreAdminUploadedFilesAction
{
    use AsFake;
    use AsObject;

    public function handle(BaseFileUpload $upload): void
    {
        // Only a server-side storage transition can add ownership. Current
        // Livewire strings must pass the existing guard before storage runs.
        ValidateAdminUploadStateAction::run($upload);
        $beforeStorage = Arr::wrap($upload->getRawState());
        $hasTemporaryUpload = collect($beforeStorage)
            ->contains(static fn (mixed $file): bool => $file instanceof TemporaryUploadedFile);

        try {
            $upload->saveUploadedFiles();
        } finally {
            // State-update hooks can fail after storage has already replaced
            // temporary files with paths. Keep that transition retryable too.
            if ($hasTemporaryUpload && $upload->shouldStoreFiles()) {
                resolve(AdminUploadOwnership::class)->remember($upload, array_values(array_filter(
                    Arr::wrap($upload->getRawState()),
                    static fn (mixed $path): bool => is_string($path) && $path !== '' && ! in_array($path, $beforeStorage, true),
                )));
            }
        }
    }
}
