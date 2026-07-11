<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Filament\Actions\Action;

interface PageEditExtender
{
    public const string TAG = 'capell-admin:page-edit-extender';

    /**
     * Form actions injected into the page edit form.
     *
     * @return array<int, Action>
     */
    public function getFormActions(): array;

    /**
     * Header widgets injected into the page edit form.
     *
     * @return array<int, mixed>
     */
    public function getHeaderWidgets(): array;
}
