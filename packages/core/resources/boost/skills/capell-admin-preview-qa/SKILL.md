---
name: capell-admin-preview-qa
description: "Tests admin-only Capell page preview behaviour: edit-page preview actions, Peek snapshots of unsaved changes, draft previews and signed snapshot safety. Use when testing or debugging Capell admin preview flows, Peek previews, unsaved page or Layout Builder state, signed preview URLs, or preview actions contributed by a package."
---

# Capell Admin Preview QA

Use this for the page edit screen's preview workflows. Keep the `capell` skill's public-output rules in force: preview and editor state never reach anonymous or non-admin responses.

## How previews are built

- The page edit header collects preview actions from every class implementing `Capell\Admin\Contracts\Extenders\PagePreviewActionExtender` and tagged with `PagePreviewActionExtender::TAG`. A package contributes an action without replacing core behaviour.
- `Capell\Admin\Filament\Resources\Pages\Actions\PreviewDraftPageHeaderAction` (`previewDraftPage`) shows only for a pending page the editor may view. It opens a signed preview URL in a new tab, issued by `IssuePagePreviewTokenAction`.
- When `capell-app/filament-peek` is installed, `PeekPagePreviewAction` (`peekPagePreview`) is the "Preview changes" action. On click it calls `CreatePagePreviewSnapshotAction` with the current Livewire form state, stores a snapshot in the cache, and dispatches `open-capell-page-preview-modal` with a temporary signed URL (route `capell-filament-peek.preview`).
- Layout Builder state that is not yet saved is carried by `StoreLayoutBuilderPreviewStateAction` and `LayoutBuilderPreviewStateData`, per user and page. Saving or resetting the layout must clear it.
- Peek settings live in `config/capell-filament-peek.php` under `preview`: `cache_store`, `ttl_minutes` (default 15), `max_payload_kb` (default 512), `route_prefix`, `middleware` and the modal device presets.

## Safety rules

- A snapshot is a temporary admin preview, not a persisted workflow draft. Nothing is written to content until the editor saves.
- The preview response must be `Cache-Control: no-store, private` with `X-Robots-Tag: noindex, nofollow`.
- A snapshot belongs to the user who created it. Another user, an unauthenticated request, an unsigned URL, an expired signature and an expired or missing snapshot must each fail with a friendly private error page and no content.
- Oversized payloads are rejected before they are cached.
- Use disposable pages and an admin test user. Do not mutate real content to test a preview.
- Accept a preview screenshot as evidence only when browser diagnostics are clean. Do not replace a failed capture with a fixture.

## Debug flow

1. Confirm the page, the signed-in role, and that `capell-app/filament-peek` is installed and enabled (`php artisan capell:doctor`).
2. Check the page edit header actions and the extender registration.
3. Check the browser console and `storage/logs` for missing assets, Livewire errors or modal dispatch failures.
4. Edit a field without saving, click the Peek action, and confirm the modal shows the unsaved value and the saved page is unchanged.
5. Change Layout Builder containers or widget assets without saving and confirm the preview reflects them.
6. Open the signed URL as an anonymous visitor and as a different user and confirm both are refused.
7. After the TTL, confirm the URL returns the expired message.

## Code review checks

- Snapshots are created at click time from current form state, not at page render time.
- Labels and tooltips use translations.
- Missing or expired snapshots and render failures return private, friendly responses, and failures are reported to the log.
- A saved layout reset clears stale preview state.

## Tests

Write focused Pest tests for:

- The extender contributes its action, and the action's visibility follows package installation and the `update` policy.
- The snapshot action captures the current form and Layout Builder state, and expires after the TTL.
- The preview route rejects unsigned, expired, unauthenticated, non-owner and non-admin requests before rendering.
- A successful preview renders the unsaved value without saving it, with private cache headers.
- Anonymous public responses contain no preview or editor markers.

```bash
vendor/bin/pest tests/Feature/PagePreviewRouteTest.php
```

Run the single test file for the behaviour you changed. Use browser QA on the real edit page for final confidence.
