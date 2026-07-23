# Admin

Use this section if you edit content, administer a site, or extend Capell's Filament panel.

| I need to...                           | Read                                                                    |
| -------------------------------------- | ----------------------------------------------------------------------- |
| Tour the admin screens                 | [Admin interface](interface.md)                                         |
| Manage users, roles, and access        | [Users and roles](users-and-roles.md)                                   |
| Keep admin accounts secure             | [Account security](account-security.md)                                 |
| Install or repair the admin panel      | [Admin setup](setup.md)                                                 |
| Understand the admin domain model      | [Admin domain](admin-domain.md)                                         |
| Register package admin surfaces        | [Admin extensions](../packages/admin-extensions.md)                     |
| Group several admin surfaces           | [Admin bridges](admin-bridges.md)                                       |
| Debug missing extension surfaces       | [Debugging admin extensions](debugging-admin-extensions.md)             |
| Customize the dashboard                | [Customize your dashboard](dashboard-customize.md)                      |
| Understand the dashboard widget system | [Dashboard widgets](dashboard-widgets.md)                               |
| Register a dashboard Filament widget   | [Register a dashboard Filament widget](dashboard-widget-development.md) |
| Work with media records                | [Media management](media-management.md)                                 |
| Manage installed themes                | [Theme Library](theme-library.md)                                       |
| Generate theme images                  | [Generated theme images](generated-theme-images.md)                     |
| Recover from broken admin state        | [Recovery Center](recovery.md)                                          |
| Find optional admin features           | [Packages and extensions](../packages/catalog.md)                       |

## Other Admin Screens

These ship with the panel but do not yet have a dedicated page in this section.

| Screen | Where | What it does |
| --- | --- | --- |
| Recently Deleted | `/admin/recently-deleted` | One place to review, restore, or permanently delete soft-deleted records across resources. Restoring here is the fastest recovery from an accidental delete. |
| Marketing Studio | `/admin/marketing-studio` | Launch-readiness overview: timeline, work queue, and quick actions. The customise modal is only available to users who can reach Settings. |
| Reports | `/admin/reports/...` | Five read-only reports — accessibility readiness, publishing readiness, public render safety, package readiness, and demo install health. Navigation visibility depends on the panel's report registration, so a report can exist without appearing in the menu. |
| Block Templates | Content section | Reusable block groupings that editors can insert into pages. |
| Page URLs | Content section | The URL records behind pages, including historical URLs kept for redirects. |
| Redirects | Content section | Manual and automatically created redirects. Capell creates a `301` automatically when a page URL changes, unless `redirects.auto_redirects.enabled` is off. |
| Activity | System section | The activity trail of who changed what. |

Capell Admin owns the host panel and its shared extension contracts. Optional feature behavior and documentation stay with the package that provides them.
