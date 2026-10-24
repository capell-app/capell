<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Media;

use Closure;
use Filament\Forms\Components\BaseFileUpload;
use ReflectionProperty;
use WeakMap;

/** Bridges native subclasses without replacing their deletion behaviour. */
final class AdminUploadDeletion
{
    /** @var WeakMap<Closure, bool> */
    private WeakMap $wrappers;

    public function __construct()
    {
        $this->wrappers = new WeakMap;
    }

    public function prepare(BaseFileUpload $upload): void
    {
        // Filament has a callback setter but no getter or post-delete hook.
        // Capture the final configured callback at authorisation time, before
        // removeUploadedFile() removes its candidate from the field state.
        $callback = new ReflectionProperty(BaseFileUpload::class, 'deleteUploadedFileUsing')->getValue($upload);
        if (! $callback instanceof Closure || ($this->wrappers[$callback] ?? false)) {
            return;
        }

        $wrapper = static function (BaseFileUpload $component, mixed $file) use ($callback): mixed {
            $revoke = is_string($file) ? resolve(AdminUploadOwnership::class)->captureRevocation($component, $file) : null;
            try {
                return $component->evaluate($callback, ['file' => $file]);
            } finally {
                // Also cover a callback throwing after it has deleted the file.
                if (is_string($file) && ! $component->getDisk()->exists($file)) {
                    $revoke?->__invoke();
                }
            }
        };
        $this->wrappers[$wrapper] = true;
        $upload->deleteUploadedFileUsing($wrapper);
    }
}
