<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Contracts\Extenders\PageAuthoringValidator;
use Capell\Core\Contracts\Pageable;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(array $formData, ?Pageable $page = null, string $operation = 'save')
 */
final class ValidatePageAuthoringAction
{
    use AsObject;

    /**
     * @param  array<string, mixed>  $formData
     */
    public function handle(array $formData, ?Pageable $page = null, string $operation = 'save'): void
    {
        foreach (app()->tagged(PageAuthoringValidator::TAG) as $validator) {
            if (! $validator instanceof PageAuthoringValidator) {
                continue;
            }

            $validator->validate($formData, $page, $operation);
        }
    }
}
