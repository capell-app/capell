<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Capell\Admin\Filament\Resources\Media\Pages\EditMedia;
use Filament\Actions\Action;

interface MediaEditActionExtender
{
    public const string TAG = 'capell-admin:media-edit-actions';

    /** @return array<int, Action> */
    public function getHeaderActions(EditMedia $page): array;
}
