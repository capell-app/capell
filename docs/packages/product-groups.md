# Package Product Groups

![Capell Package Product Groups screenshot](../images/generated/admin/theme-library-admin-flow.png)

Capell packages are grouped by customer-facing value, not by internal implementation detail. The Composer package names remain stable, while package manifests expose `productGroup`, `tier`, and `bundle` for catalogue, marketplace, and pricing screens.

## Free Foundation

**Capell Foundation** is the free baseline. It contains the capabilities most sites expect from a practical CMS:

| Package            | Composer name                   | Why it is free                                                                                                        |
| ------------------ | ------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| ContentSections    | `capell-app/content-sections`   | Visual page building is a core CMS expectation.                                                                       |
| Blog               | `capell-app/blog`               | Articles, archives, RSS-style content, and basic publishing belong in the baseline.                                   |
| Navigation         | `capell-app/navigation`         | Header, footer, and sidebar menus are foundational.                                                                   |
| Tags               | `capell-app/tags`               | Shared taxonomy supports normal content organisation.                                                                 |
| Address            | `capell-app/address`            | Country and address fields are common site data.                                                                      |
| Media Library      | `capell-app/media-library`      | Media management is foundational, even when a project chooses Curator over Spatie MediaLibrary.                       |
| Frontend Authoring | `capell-app/frontend-authoring` | Admin-only in-page editing is a baseline authoring convenience, but public visitors must never receive editor markup. |
| Frontend Default   | `capell-app/frontend`           | Every install gets a minimal working frontend theme fallback without a separate theme package.                        |

## Premium Groups

Premium groups are capabilities that save operational time, reduce publishing risk, or support commercial workflows.

| Product group         | Bundle key       | Packages                                                                         | Buying reason                                                                                                                                                  |
| --------------------- | ---------------- | -------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Capell Commercial     | `commercial`     | AIOrchestrator, AI Creator                                                       | Centralise AI prompts, provider connectors, approval-aware runs, reviewed creation sessions, and package recommendations without pushing commercial dependencies into free packages. |
| Capell FormBuilder    | `form-builder`   | FormBuilder                                                                      | Capture leads and manage submissions inside the Laravel app.                                                                                                   |
| Capell Publishing Pro | `publishing-pro` | PublishingStudio                                                                 | Run team editorial workflows with drafts, approvals, previews, schedules, and version history.                                                                 |
| Capell Operations     | `operations`     | Migration Assistant, Diagnostics, Site Monitor, Exception Reports, Login Audit   | Keep serious sites healthy, monitored, auditable, recoverable, and safer to operate.                                                                           |
| Capell Growth         | `growth`         | Insights, CampaignStudio                                                         | Measure traffic, build campaigns, track conversions, and report marketing performance.                                                                         |
| Capell Communications | `communications` | Email Studio                                                                     | Give transactional email a proper command centre: templates, profiles, audit trails, suppressions, provider events, replies, and tracking diagnostics.         |
| Capell Search & SEO   | `search-seo`     | SEO Suite, Search                                                                | Improve discoverability with audits, structured data, AI SEO assistance, search, and search insights.                                                          |
| Capell Themes         | `themes`         | Agency Theme, Corporate Theme, Estate Agents Theme, Restaurant Theme, SaaS Theme | Launch polished branded sites faster with independently installable premium themes.                                                                            |

## Manifest Fields

Every first-party `capell.json` should include these fields:

```json
{
    "productGroup": "Capell Operations",
    "tier": "premium",
    "bundle": "operations"
}
```

Use `tier: "free"` for Capell Foundation packages and explicitly free support packages. Use `tier: "premium"` for paid product-group packages.

The `bundle` value is a stable machine key. The `productGroup` value is display copy and may appear in marketplace, installer, and documentation surfaces.

## Compatibility Rule

Do not rename existing Composer packages just to change commercial grouping. Keep package names, namespaces, database tables, commands, and config keys stable unless there is a separate technical reason to migrate them.

The grouping layer lets `capell-app/migration-assistant`, `capell-app/diagnostics`, `capell-app/site-monitor`, and `capell-app/login-audit` sell together as **Capell Operations** without forcing a risky code-level merge.
