<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Capell\Core\Contracts\Pageable;
use Illuminate\Database\Eloquent\Model;

/**
 * Adds extension-owned validation to page create and edit operations.
 *
 * Bind implementations in the service container and tag them with TAG.
 * Admin invokes every validator before persisting authoring form data; throw a
 * validation/domain exception to reject the operation.
 */
interface PageAuthoringValidator
{
    public const string TAG = 'capell-admin:page-authoring-validator';

    /**
     * Validate the complete authoring payload for the requested operation.
     *
     * @param  array<string, mixed>  $formData
     * @param  Pageable<covariant Model>|null  $page
     */
    public function validate(array $formData, ?Pageable $page = null, string $operation = 'save'): void;
}
