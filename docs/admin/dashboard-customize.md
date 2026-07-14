# Customize your dashboard


The dashboard shows role-aware widgets. To change which widgets appear and their order:

1. Open **Settings → Dashboard**.
2. Enable or disable widgets with the **enabled widgets** control.
3. Reorder them with the **widget order** control.
4. Save. The dashboard reflects the change on next load.

Some widgets only appear for users with the matching role or permission, so a teammate may see a different set.

Marketing Studio and Extensions also expose dashboard customisation from their own pages when the current user can access settings.

## Widget Limits

Some widgets read a numeric limit that controls how many entries they show or how often they refresh. These are set alongside the dashboard settings above:

| Setting                                 | Default | Purpose                                                                              |
| --------------------------------------- | ------: | ------------------------------------------------------------------------------------ |
| `my_work_queue_limit`                   |    `15` | Number of editor work-queue entries.                                                 |
| `recently_published_limit`              |    `10` | Number of recently published entries.                                                |
| `cache_health_refresh_interval_seconds` |    `60` | Cache health refresh interval.                                                       |
| `ai_orchestrator_spend_window_days`     |    `30` | Lookback window for AI orchestrator spend aggregates when that feature is installed. |

## Next

- [Admin Dashboard Widgets](dashboard-widgets.md)
- [Register a dashboard Filament widget](dashboard-widget-development.md)
