<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Contracts\Extenders\PageAuthoringValidator;
use Capell\Core\Contracts\Pageable;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(array<string, mixed> $formData, Pageable<covariant Model>|null $page = null, string $operation = 'save')
 */
final class ValidatePageAuthoringAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $formData
     * @param  Pageable<covariant Model>|null  $page
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
