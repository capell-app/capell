<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Illuminate\Database\Eloquent\Model;

interface UserFormExtender
{
    public const string TAG = 'capell.admin.user-form-extender';

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mutateDataBeforeCreate(array $data): array;

    public function afterCreate(Model $record): void;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mutateDataBeforeSave(Model $record, array $data): array;

    public function afterSave(Model $record): void;
}
