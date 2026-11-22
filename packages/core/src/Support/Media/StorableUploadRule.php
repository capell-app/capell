<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

use Capell\Core\Exceptions\UploadContentRefusedException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Override;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Form validation for one uploaded file against the upload content policy, so
 * the person uploading reads why a file was refused before anything is stored.
 */
final readonly class StorableUploadRule implements ValidationRule
{
    #[Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $refusal = resolve(UploadContentPolicy::class)->refusalForUpload($value);

        if ($refusal instanceof UploadContentRefusedException) {
            $fail($refusal->getMessage());
        }
    }
}
