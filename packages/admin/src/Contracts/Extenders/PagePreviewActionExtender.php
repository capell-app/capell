<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Filament\Actions\Action;

interface PagePreviewActionExtender
{
    public const string TAG = 'capell-admin:page-preview-actions';

    /** @return array<int, Action> */
    public function actions(): array;
}
