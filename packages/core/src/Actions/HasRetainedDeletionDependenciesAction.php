<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsObject;

/** Integrity checks include all sites and retained trash, independently of author visibility. */
final class HasRetainedDeletionDependenciesAction
{
    use AsObject;

    public function handle(Model $record): bool
    {
        return match (true) {
            $record instanceof Site => $record->pages()->withTrashed()->exists()
                || $record->siteDomains()->withTrashed()->exists()
                || $record->layouts()->withTrashed()->exists(),
            $record instanceof Page => $record->canonicalPages()->withTrashed()->exists(),
            $record instanceof Layout => $record->pages()->withTrashed()->exists(),
            $record instanceof Theme => $record->sites()->withTrashed()->exists(),
            $record instanceof Blueprint => $record->pages()->withTrashed()->exists()
                || $record->sites()->withTrashed()->exists()
                || $record->themes()->withTrashed()->exists(),
            $record instanceof Language => $record->sitesLanguage()->withTrashed()->exists()
                || $record->sites()->withTrashedParents()->withTrashed()->exists(),
            default => false,
        };
    }
}
