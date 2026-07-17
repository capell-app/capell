<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Support\Creator\PageCreator;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * @method static void run(Site $site, ?Collection<int, Language> $languages = null, ?array<int, string> $pages = null)
 */
class CreateDefaultPagesAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Collection<int, Language>|null  $languages
     * @param  array<int, string>|null  $pages
     *
     * @throws Exception
     */
    public function handle(Site $site, ?Collection $languages = null, ?array $pages = null): void
    {
        if (! $languages instanceof Collection) {
            $languages = $site->languages;
        }

        if ($pages === null) {
            $pages = CapellCore::getDefaultPages()->keys();
        }

        $pageCreator = resolve(PageCreator::class);

        foreach ($pages as $type) {
            switch ($type) {
                case 'home':
                    $pageCreator->createHomePage($site, $languages);
                    break;
                case 'error_404':
                    $pageCreator->createErrorPage($site, $languages);
                    break;
                case 'maintenance':
                    $pageCreator->createMaintenancePage($site, $languages);
                    break;
                case 'welcome':
                    $pageCreator->createWelcomePage($site, $languages);
                    break;
                default:
                    $callback = CapellCore::getDefaultPage($type)->callback ?? null;

                    try {
                        throw_unless(
                            is_callable($callback),
                            Exception::class,
                            'Invalid callback for default page: ' . $type,
                        );

                        app()->call($callback, ['site' => $site, 'languages' => $languages]);
                    } catch (Throwable $e) {
                        throw new Exception(sprintf('Failed to create default page: %s. Error: %s', $type, $e->getMessage()), 0, $e);
                    }
            }
        }
    }
}
