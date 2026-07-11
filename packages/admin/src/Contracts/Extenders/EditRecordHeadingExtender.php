<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

interface EditRecordHeadingExtender
{
    public const string TAG = 'capell-admin:edit-record-heading';

    public function supports(EditRecord $page): bool;

    public function heading(EditRecord $page, string|Htmlable $default): string|Htmlable;

    public function saved(EditRecord $page): void;
}
