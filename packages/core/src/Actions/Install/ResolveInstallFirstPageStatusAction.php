<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Models\Page;
use Capell\Core\Support\TableName;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Describes the first page an install can hand off to: `editable` when a page
 * exists and Admin can open it, `present_unverified` when a page exists without
 * an edit URL, `missing` when there is no page or no pages table yet, and
 * `unavailable` when the state cannot be read.
 *
 * @method static string run()
 */
final class ResolveInstallFirstPageStatusAction
{
    use AsFake;
    use AsObject;

    public function handle(): string
    {
        try {
            if (! Schema::hasTable(TableName::resolve('pages'))) {
                return 'missing';
            }

            $page = Page::query()->with('blueprint')->first();

            if (! $page instanceof Page) {
                return 'missing';
            }

            return GetEditPageResourceUrlAction::run($page) !== null
                ? 'editable'
                : 'present_unverified';
        } catch (Throwable) {
            return 'unavailable';
        }
    }
}
