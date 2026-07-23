<?php

declare(strict_types=1);

/**
 * Config leaves that intentionally remain absent from user-facing docs.
 *
 * Keep entries exact and explain whether the path is internal or a deliberate
 * documentation classification. The coverage check rejects stale entries and
 * paths without a reason.
 *
 * @return array<string, string>
 */
$internalAdminPresentation = [
    'capell-admin.assets.media.color',
    'capell-admin.assets.media.icon',
    'capell-admin.assets.media.model',
    'capell-admin.assets.page.color',
    'capell-admin.assets.page.icon',
    'capell-admin.assets.page.model',
    'capell-admin.assets.page.navigation_badge',
    'capell-admin.assets.user.icon',
    'capell-admin.assets.user.navigation_badge',
    'capell-admin.icon.admin',
    'capell-admin.icon.colors',
    'capell-admin.icon.theme',
    'capell-admin.resources.blueprint.active_icon',
    'capell-admin.resources.blueprint.icon',
    'capell-admin.resources.layout.active_icon',
    'capell-admin.resources.layout.icon',
    'capell-admin.resources.page_redirects.icon',
    'capell-admin.resources.page_redirects.navigation_badge',
    'capell-admin.resources.type.active_icon',
    'capell-admin.resources.type.icon',
];

$internalDefaultColors = [
    'capell.default_colors.base',
    'capell.default_colors.black',
    'capell.default_colors.border',
    'capell.default_colors.danger',
    'capell.default_colors.dark_gray',
    'capell.default_colors.gray',
    'capell.default_colors.info',
    'capell.default_colors.light_gray',
    'capell.default_colors.muted',
    'capell.default_colors.primary',
    'capell.default_colors.secondary',
    'capell.default_colors.success',
    'capell.default_colors.warning',
    'capell.default_colors.white',
];

return [
    ...array_fill_keys(
        $internalAdminPresentation,
        'Internal Filament presentation defaults; extension authors use the registered admin extension points.',
    ),
    'capell-admin.user_resource.default_schema_type' => 'Internal fallback for the built-in user resource schema.',
    'capell-frontend.fastly_service_id' => 'Public CDN integration key; classified for the ranked configuration documentation backlog.',
    'capell-frontend.foundation_theme' => 'Public fallback paired with default_layout; classified by the existing combined configuration row.',
    'capell-frontend.tailwind.imports' => 'Public advanced build input; classified for the ranked frontend configuration documentation backlog.',
    'capell-frontend.tailwind.plugins' => 'Public advanced build input; classified for the ranked frontend configuration documentation backlog.',
    'capell-installer.database_table_cache.key' => 'Internal cache namespace, not a supported installer override.',
    'capell-installer.installation_state_cache.host' => 'Internal test isolation seam, not a supported installer override.',
    'capell-installer.installation_state_cache.key' => 'Internal cache namespace, not a supported installer override.',
    ...array_fill_keys(
        $internalDefaultColors,
        'Internal seed palette consumed through DefaultColorEnum; themes expose their own color contract.',
    ),
    'capell.diagnostics.allowed_roots' => 'Public diagnostics setting covered by the existing diagnostics.allowed_roots shorthand.',
    'capell.plugins' => 'Internal runtime catalogue cache populated by package discovery.',
    'capell.publishing-studio.notifications.channels' => 'Public publishing-studio policy covered by the documented notifications wildcard.',
    'capell.publishing-studio.notifications.recipients.abandoned' => 'Public publishing-studio policy covered by the documented notifications wildcard.',
    'capell.publishing-studio.notifications.recipients.approved' => 'Public publishing-studio policy covered by the documented notifications wildcard.',
    'capell.publishing-studio.notifications.recipients.changes_requested' => 'Public publishing-studio policy covered by the documented notifications wildcard.',
    'capell.publishing-studio.notifications.recipients.published' => 'Public publishing-studio policy covered by the documented notifications wildcard.',
    'capell.publishing-studio.notifications.recipients.rejected' => 'Public publishing-studio policy covered by the documented notifications wildcard.',
    'capell.publishing-studio.notifications.recipients.submitted' => 'Public publishing-studio policy covered by the documented notifications wildcard.',
    'capell.publishing-studio.review_policy.content_types' => 'Public publishing-studio policy covered by the documented review_policy wildcard.',
    'capell.publishing-studio.review_policy.default.minimum' => 'Public publishing-studio policy covered by the documented review_policy wildcard.',
    'capell.sitemap.directory' => 'Internal sitemap storage layout paired with the fixed local disk.',
    'capell.sitemap.disk' => 'Internal sitemap storage disk; public sitemap behavior is configured through documented URL settings.',
];
