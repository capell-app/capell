# Preparing a Marketplace listing

## What the manifest must carry

- `marketplace.summary`: non-empty buyer-facing text that names the outcome and the workflow the package improves.
- `marketplace.categories`: a list of strings.
- `marketplace.screenshots`: a list. Each entry needs `path`, `alt` and `caption`. `path` is package-relative with no leading slash, no `..` and no backslashes; `alt` and `caption` are at least 12 characters of descriptive text. Commit every referenced file.
- Optional `marketplace.problem`, `marketplace.outcome` (at least 12 characters each) and `marketplace.faq` (a list of objects with only `question` and `answer`, at least 12 characters each).
- `marketplace.hidden` is a boolean that keeps a package out of the catalogue.
- `product.group` and `product.tier` are required and group the listing; `product.bundle` is optional. `commercial` is required. It may contain only `proposedLicense`, `requestedCertification`, `supportPolicy` and a boolean `privateDocsRequested`, and it is author proposal metadata, not a price or certification.
- `documentationUrl` is optional and overrides the documentation link taken from `composer.json` `support.docs`.

## Copy

Write for the buyer. State the outcome and the visible surfaces first, then safety and integration benefits. Avoid empty screenshots, vague summaries and screenshots that do not show a real admin or frontend workflow.

## Screenshots

Capture real workflows from a running application with clean browser diagnostics: one card or hero image, one for each primary admin or frontend workflow, and one for any developer-facing seam you sell. Do not use placeholder or generated images as evidence of a workflow.

## Before you submit

1. Tag a Composer release and confirm it installs through a normal Composer repository (Packagist, private Composer or VCS).
2. Run the package tests, `php artisan capell:package:lint` and `php artisan capell:extension-audit` on the package path. Any result with `severity=error` fails.
3. Confirm the README names setup, support and any privacy, data or third-party disclosures.
4. Confirm each declared contribution has a class, a test and a shipped surface, and that screenshot paths exist.

`manifest-version` is the schema version and `version` is your release version; neither sets Marketplace maturity. Reviewers assign the Marketplace state through the submission flow, and an approved community submission starts in Labs. Pricing and certification are decided through that flow, not by editing the manifest alone.
