<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Data\AdminHelpAnswerData;
use Capell\Admin\Data\AdminHelpEntryData;

/**
 * Help for the screens Core ships, keyed by admin route name. Translation keys
 * stay literal so the language-key checks can see every one of them.
 */
final class CoreAdminHelpEntries
{
    public static function register(AdminHelpRegistry $registry): void
    {
        foreach (self::entries() as $route => $entry) {
            $registry->register($route, $entry);
        }
    }

    /** @return array<string, AdminHelpEntryData> */
    public static function entries(): array
    {
        return [
            'filament.admin.resources.pages.index' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.pages.title'),
                summary: (string) __('capell-admin::help.pages.summary'),
                nextAction: (string) __('capell-admin::help.pages.next'),
                answers: [
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.pages.answers.add_page.question'),
                        (string) __('capell-admin::help.pages.answers.add_page.answer'),
                    ),
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.pages.answers.find_page.question'),
                        (string) __('capell-admin::help.pages.answers.find_page.answer'),
                    ),
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.pages.answers.move_page.question'),
                        (string) __('capell-admin::help.pages.answers.move_page.answer'),
                    ),
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.pages.answers.remove_page.question'),
                        (string) __('capell-admin::help.pages.answers.remove_page.answer'),
                    ),
                ],
            ),
            'filament.admin.resources.pages.edit' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.page_edit.title'),
                summary: (string) __('capell-admin::help.page_edit.summary'),
                nextAction: (string) __('capell-admin::help.page_edit.next'),
                answers: [
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.page_edit.answers.change_content.question'),
                        (string) __('capell-admin::help.page_edit.answers.change_content.answer'),
                    ),
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.page_edit.answers.publish.question'),
                        (string) __('capell-admin::help.page_edit.answers.publish.answer'),
                    ),
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.page_edit.answers.change_address.question'),
                        (string) __('capell-admin::help.page_edit.answers.change_address.answer'),
                    ),
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.page_edit.answers.restore_version.question'),
                        (string) __('capell-admin::help.page_edit.answers.restore_version.answer'),
                    ),
                ],
            ),
            'filament.admin.resources.media.index' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.media.title'),
                summary: (string) __('capell-admin::help.media.summary'),
                nextAction: (string) __('capell-admin::help.media.next'),
                answers: [
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.media.answers.upload.question'),
                        (string) __('capell-admin::help.media.answers.upload.answer'),
                    ),
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.media.answers.describe_image.question'),
                        (string) __('capell-admin::help.media.answers.describe_image.answer'),
                    ),
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.media.answers.find_usage.question'),
                        (string) __('capell-admin::help.media.answers.find_usage.answer'),
                    ),
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.media.answers.delete_used_file.question'),
                        (string) __('capell-admin::help.media.answers.delete_used_file.answer'),
                    ),
                ],
            ),
            'filament.admin.resources.sites.index' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.sites.title'),
                summary: (string) __('capell-admin::help.sites.summary'),
                nextAction: (string) __('capell-admin::help.sites.next'),
            ),
            'filament.admin.resources.layouts.index' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.layouts.title'),
                summary: (string) __('capell-admin::help.layouts.summary'),
                nextAction: (string) __('capell-admin::help.layouts.next'),
            ),
            'filament.admin.resources.themes.index' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.themes.title'),
                summary: (string) __('capell-admin::help.themes.summary'),
                nextAction: (string) __('capell-admin::help.themes.next'),
            ),
            'filament.admin.resources.blueprints.index' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.blueprints.title'),
                summary: (string) __('capell-admin::help.blueprints.summary'),
                nextAction: (string) __('capell-admin::help.blueprints.next'),
            ),
            'filament.admin.resources.users.index' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.users.title'),
                summary: (string) __('capell-admin::help.users.summary'),
                nextAction: (string) __('capell-admin::help.users.next'),
                answers: [
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.users.answers.invite.question'),
                        (string) __('capell-admin::help.users.answers.invite.answer'),
                    ),
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.users.answers.invitation_missing.question'),
                        (string) __('capell-admin::help.users.answers.invitation_missing.answer'),
                    ),
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.users.answers.change_role.question'),
                        (string) __('capell-admin::help.users.answers.change_role.answer'),
                    ),
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.users.answers.remove_access.question'),
                        (string) __('capell-admin::help.users.answers.remove_access.answer'),
                    ),
                ],
            ),
            'filament.admin.resources.shield.roles.index' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.roles.title'),
                summary: (string) __('capell-admin::help.roles.summary'),
                nextAction: (string) __('capell-admin::help.roles.next'),
            ),
            'filament.admin.pages.settings' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.settings.title'),
                summary: (string) __('capell-admin::help.settings.summary'),
                nextAction: (string) __('capell-admin::help.settings.next'),
                answers: [
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.settings.answers.what_changes.question'),
                        (string) __('capell-admin::help.settings.answers.what_changes.answer'),
                    ),
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.settings.answers.visitor_impact.question'),
                        (string) __('capell-admin::help.settings.answers.visitor_impact.answer'),
                    ),
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.settings.answers.undo_change.question'),
                        (string) __('capell-admin::help.settings.answers.undo_change.answer'),
                    ),
                    new AdminHelpAnswerData(
                        (string) __('capell-admin::help.settings.answers.hidden_settings.question'),
                        (string) __('capell-admin::help.settings.answers.hidden_settings.answer'),
                    ),
                ],
            ),
            'filament.admin.pages.extensions' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.extensions.title'),
                summary: (string) __('capell-admin::help.extensions.summary'),
                nextAction: (string) __('capell-admin::help.extensions.next'),
            ),
            'filament.admin.resources.redirects.index' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.redirects.title'),
                summary: (string) __('capell-admin::help.redirects.summary'),
                nextAction: (string) __('capell-admin::help.redirects.next'),
            ),
            'filament.admin.pages.sitemap' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.sitemap.title'),
                summary: (string) __('capell-admin::help.sitemap.summary'),
                nextAction: (string) __('capell-admin::help.sitemap.next'),
            ),
            'filament.admin.pages.site-health' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.site_health.title'),
                summary: (string) __('capell-admin::help.site_health.summary'),
                nextAction: (string) __('capell-admin::help.site_health.next'),
            ),
            'filament.admin.resources.languages.index' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.languages.title'),
                summary: (string) __('capell-admin::help.languages.summary'),
                nextAction: (string) __('capell-admin::help.languages.next'),
            ),
            'filament.admin.resources.activities.index' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.activity.title'),
                summary: (string) __('capell-admin::help.activity.summary'),
                nextAction: (string) __('capell-admin::help.activity.next'),
            ),
            'filament.admin.pages.recently-deleted' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.recently_deleted.title'),
                summary: (string) __('capell-admin::help.recently_deleted.summary'),
                nextAction: (string) __('capell-admin::help.recently_deleted.next'),
            ),
            'filament.admin.pages.analytics-dashboard' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.analytics.title'),
                summary: (string) __('capell-admin::help.analytics.summary'),
                nextAction: (string) __('capell-admin::help.analytics.next'),
            ),
            'filament.admin.pages.upgrade' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.upgrade.title'),
                summary: (string) __('capell-admin::help.upgrade.summary'),
                nextAction: (string) __('capell-admin::help.upgrade.next'),
            ),
            'filament.admin.pages.capell-dashboard' => new AdminHelpEntryData(
                title: (string) __('capell-admin::help.dashboard.title'),
                summary: (string) __('capell-admin::help.dashboard.summary'),
                nextAction: (string) __('capell-admin::help.dashboard.next'),
            ),
        ];
    }
}
