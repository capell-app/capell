# Export and exit plan

Capell does not hold content in a hosted black box. The application database, configured media disks, source code, Composer lock file, and deployment configuration remain under the operator's control. A usable exit still needs planning because themes, layouts, package-owned records, and application-specific relationships are not interchangeable with another CMS.

## What you can take

| Asset | Source of truth | Exit treatment |
| --- | --- | --- |
| Pages, sites, languages, URLs, and settings | Application database | Export through Migration Assistant where supported, or read from the documented Eloquent/database model in a controlled migration. |
| Media binaries | Configured Laravel filesystem disks | Copy original files and export metadata, ownership, alt text, focal points, and translations. |
| Page and site packages | Migration Assistant ZIP archives | Keep `manifest.json`, JSON payloads, media files, and `integrity.json` together. |
| Redirects and URL history | Page URL and redirect records | Export before changing DNS so legacy paths can be recreated at the destination. |
| Theme and frontend code | Application/package source | Port presentation intentionally; a Capell Blade theme is not a portable theme format for another CMS. |
| Package-owned domain records | Each package's tables and export contributors | Inventory installed packages and confirm their export coverage before setting a cutover date. |
| Operational evidence | Deploy records, backup manifests, logs, and configuration | Retain under the organisation's audit/privacy policy. Never publish secrets with an export. |

## Create a portable content package

Migration Assistant is the supported page/site transfer path when installed:

```bash
php artisan migration-assistant:export --site=12 --note="Exit rehearsal 2026-07-12" --json
php artisan migration-assistant:export --page=42 --page=43 --json
```

Exports include translations, media, and supported shared relations by default. Use `--without-translations`, `--without-media`, or `--without-shared-relations` only when the destination plan explicitly excludes them. The command writes a deterministic ZIP with checksums; copy it off the application host and verify that it opens before treating it as evidence.

An export package is designed for Capell-to-Capell transfer. Another CMS needs a transformation step from the archive's JSON and media into its own content model. Keep that transformer separate from production and test it against a restored copy.

## Rehearse the exit

1. Inventory sites, languages, public URLs, redirects, media disks, installed packages, scheduled jobs, and external integrations.
2. Create a database/media backup and a Migration Assistant export from a non-production copy.
3. Import or transform the export into an empty destination environment.
4. Compare record counts, relationships, translations, media checksums, canonical URLs, redirects, and representative rendered pages.
5. Crawl both sites and record every intentional URL change.
6. Time the export, transformation, media copy, DNS change, and rollback so the cutover window is evidence-based.

## Cutover and retention

Freeze editing or record a final delta, create the final export, copy media, run the tested transformation, install redirects, and verify the destination before changing DNS. Keep Capell read-only until the rollback window expires. Afterwards, revoke package tokens and third-party credentials, apply the retention policy, and securely remove personal data that no longer has a lawful purpose.

Do not delete the source environment merely because the homepage works. Sign off record counts, media integrity, redirects, forms, authentication boundaries, scheduled jobs, analytics, consent records, and the rollback decision first.

Related runbooks: [Backups and restore](backups.md), [Recovery Center](../admin/recovery.md), and [Upgrading](upgrading.md).
