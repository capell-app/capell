<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Admin\Filament\Components\Forms\TranslationsRepeater;
use Capell\Admin\Support\Filament\RecoverableSchemaComponents;

/**
 * Restores form state recovered from a scratch draft or a creation recovery. The
 * recovered state can hold another schema shape (stored, list-keyed blocks saved
 * before the content structure or a field changed), so each translation's content
 * is presented and hydrated as a fresh mount would, without reloading the rows
 * from the database: the recovered edits are what the editor asked to restore.
 */
trait RestoresRecoveredFormState
{
    /**
     * @param  array<string, mixed>  $state
     */
    protected function restoreRecoveredFormState(array $state): void
    {
        $this->data = $state;

        unset($this->cachedSchemas['form']);

        $form = $this->getSchema('form');

        if ($form === null) {
            return;
        }

        foreach (RecoverableSchemaComponents::in($form) as $component) {
            if ($component instanceof TranslationsRepeater) {
                $component->hydrateRestoredContent();
            }
        }
    }
}
