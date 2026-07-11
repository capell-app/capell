# Relationship Diagnostics

![Capell Relationship Diagnostics screenshot](./images/screenshots/core-page-structure.png)

Capell can emit focused diagnostics when a page URL cannot resolve the active site domain needed to build `PageUrl::full_url`.

Use this when errors look like:

```text
Capell\Core\Exceptions\UrlMissingSiteDomainException
Site domain not found for page ID ..., site ID ..., and language ID ...
```

This diagnostic is disabled by default. It only logs when `PageUrl::full_url` is already about to throw, so enabling it does not change URL resolution or hide missing data.

## Enable Diagnostics

Set this in the host app:

```env
CAPELL_RELATIONSHIP_DIAGNOSTICS=true
```

Then reproduce the failing request and inspect the Laravel log:

```bash
grep "Capell PageUrl full_url could not resolve an active site domain" storage/logs/laravel.log
```

Turn the flag off after the incident. The log context includes model IDs, route details, and relationship state that are useful for debugging but too noisy for normal production logs.

## What Gets Logged

The log entry is written at `warning` level with structured context from `DiagnosePageUrlSiteDomainAction`.

Key fields:

| Field                            | Meaning                                                                |
| -------------------------------- | ---------------------------------------------------------------------- |
| `page_url_id`                    | The `page_urls.id` being rendered.                                     |
| `pageable_type`, `pageable_id`   | The owning content record, usually a page.                             |
| `site_id`, `language_id`         | Composite keys used by `PageUrl::siteDomain()`.                        |
| `site_domain_relation_loaded`    | Whether Eloquent had already loaded the `siteDomain` relation.         |
| `loaded_site_domain_is_null`     | Whether the loaded relation was explicitly `null`.                     |
| `active_site_domain_exists`      | Whether an active, non-deleted matching `site_domains` row exists.     |
| `active_site_domain_id`          | Matching active domain ID, when present.                               |
| `trashed_site_domain_exists`     | Whether a soft-deleted matching domain exists.                         |
| `trashed_site_domain_id`         | Matching soft-deleted domain ID, when present.                         |
| `trashed_site_domain_deleted_at` | Deletion timestamp for the matching soft-deleted domain, when present. |
| `route_name`, `request_path`     | Request that triggered the failing URL render.                         |
| `caller`                         | First useful non-framework caller found in the backtrace.              |

## Interpreting Results

If `active_site_domain_exists` is `false` and `trashed_site_domain_exists` is `true`, the page URL points at a language/site pair whose domain was soft-deleted. Restore or recreate the `site_domains` row, or remove/update the affected `page_urls` row.

If `site_domain_relation_loaded` is `true` and `loaded_site_domain_is_null` is `true` while `active_site_domain_exists` is also `true`, look for a relationship load that selected too few columns or used incompatible constraints. Composite relationships need both `site_id` and `language_id` on the parent and related models.

If both active and trashed domain checks are false, inspect the site's language setup. The page URL may have been created for a language that no longer has a domain for that site.

## Common Checks

Use the IDs from the log entry:

```sql
select id, pageable_type, pageable_id, site_id, language_id, url, deleted_at
from page_urls
where id = ?;

select id, site_id, language_id, domain, path, scheme, status, deleted_at
from site_domains
where site_id = ? and language_id = ?
order by deleted_at is null desc, id desc;
```

For admin table failures, check any `VisitUrlAction` or table column that calls `full_url`. Admin surfaces should not render an open/visit link unless the selected `PageUrl` has an active `SiteDomain`.
