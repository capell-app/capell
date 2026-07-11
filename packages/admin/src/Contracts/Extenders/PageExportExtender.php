<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Filament\Schemas\Components\Component;

interface PageExportExtender
{
    public const string TAG = 'capell-admin:page-export-extender';

    /**
     * Extra form fields added to page/site export modals.
     *
     * @return array<int, Component>
     */
    public function getFormFields(): array;

    /**
     * Resolve extra export options from submitted form data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolveOptions(array $data): array;
}
