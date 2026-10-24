<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Media;

use Filament\Forms\Components\BaseFileUpload;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ValidateAdminUploadStateAction
{
    use AsFake;
    use AsObject;

    public function handle(BaseFileUpload $upload): void
    {
        $paths = ResolveAdminUploadOriginalPathsAction::run($upload);

        foreach (Arr::wrap($upload->getRawState()) as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                continue;
            }

            if (! is_string($file)) {
                continue;
            }

            if (in_array($file, $paths, true)) {
                continue;
            }

            throw ValidationException::withMessages([
                $upload->getStatePath() => __($upload->getValidationMessages()['tampered'] ?? 'filament-forms::validation.tampered_file_path', [
                    'attribute' => $upload->getValidationAttribute(),
                ]),
            ]);
        }
    }
}
