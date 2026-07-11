<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Capell\Core\Contracts\Pageable;

interface PageAuthoringValidator
{
    public const string TAG = 'capell-admin:page-authoring-validator';

    /**
     * @param  array<string, mixed>  $formData
     */
    public function validate(array $formData, ?Pageable $page = null, string $operation = 'save'): void;
}
