<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard;

use Capell\Admin\Actions\Dashboard\DismissFirstRunChecklistAction;
use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Data\Dashboard\FirstRunStateData;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Support\Dashboard\AdminDashboardDataRequestCache;
use Capell\Admin\Support\Workspace\AdminWorkspacePreferenceStore;
use Capell\Core\Models\Site;
use Capell\Core\Support\FirstRun\SitePublicationMilestones;
use Capell\Core\Support\Permissions\SiteAccess;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Override;

final class FirstRunChecklistFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    protected static string $settingsKey = '';

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['editor', 'admin', 'super_admin'];

    protected string $view = 'capell-admin::widgets.dashboard.first-run-checklist';

    protected int|string|array $columnSpan = 'full';

    /** Renders with the page so the dashboard never shows an empty placeholder card while it loads. */
    protected static bool $isLazy = false;

    #[Override]
    public static function canView(): bool
    {
        return self::canViewCheck() && self::resolveState()->showsChecklist();
    }

    /** Whether the install is still being set up for this person, whether or not they hid the list. */
    public static function isFirstRun(): bool
    {
        return self::canViewCheck() && self::resolveState()->isFirstRun;
    }

    /** Whether the dashboard holds only setup work: no site this person can access is live, whether or not they hid the list. */
    public static function replacesDashboard(): bool
    {
        return self::canViewCheck() && self::resolveState()->replacesDashboard();
    }

    /**
     * Whether the account menu should offer the hidden list again. Runs on every
     * admin page, so it reads only this person's preference and the recorded
     * go-live dates, never the checklist itself.
     */
    public static function canBeRestored(): bool
    {
        $actor = auth()->user();
        if (! $actor instanceof Model || ! self::canViewCheck()
            || ! resolve(AdminWorkspacePreferenceStore::class)->firstRunChecklistHidden($actor)) {
            return false;
        }

        return SiteAccess::forActor($actor)->query(Site::class)
            ->whereKeyNot(resolve(SitePublicationMilestones::class)->publishedSiteIds())
            ->exists();
    }

    public function state(): FirstRunStateData
    {
        return self::resolveState();
    }

    public function hide(): void
    {
        $actor = auth()->user();
        if (! $actor instanceof Model) {
            return;
        }

        DismissFirstRunChecklistAction::run($actor);

        Notification::make()
            ->title(__('capell-admin::dashboard.first_run_hidden_title'))
            ->body(__('capell-admin::dashboard.first_run_hidden_body'))
            ->success()
            ->send();

        $this->redirect(Filament::getUrl() ?? url('/'), navigate: true);
    }

    private static function resolveState(): FirstRunStateData
    {
        $actor = auth()->user();

        return $actor instanceof Authenticatable
            ? resolve(AdminDashboardDataRequestCache::class)->firstRunState($actor)
            : FirstRunStateData::notFirstRun();
    }
}
