# Grouped Foreign Record Actions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let Page rows open their related layout and edit their blueprint in a modal, with Visit page contained in the row action group.

**Architecture:** `PagesTable` already eager-loads `layout` and `type`, so it can define contextual record actions without a query change. The layout action uses the overrideable Layout resource URL; the blueprint action reuses the established Blueprint table modal configuration so Blueprint remains route-free.

**Tech Stack:** Laravel 12, PHP 8.4, Filament 4, Pest.

---

### Task 1: Cover grouped Page actions

**Files:**
- Modify: `packages/admin/tests/Feature/Filament/Resources/Page/Pages/ListPagesTest.php`

- [ ] **Step 1: Write failing action assertions**

Add imports for `LayoutResource` and `ActionGroup`, then add a test that creates a Page with a Layout and Blueprint. Inspect the table actions through `getFlatActions()` and assert that the Page edit action remains top-level while `visit-page`, `edit-layout`, and `edit-blueprint` are nested in the `ActionGroup`. Assert the layout action resolves to:

```php
LayoutResource::getUrl('edit', ['record' => $page->layout])
```

Mount `edit-blueprint` for the Page and assert the modal contains the Blueprint name.

- [ ] **Step 2: Run the focused test and confirm it fails**

Run: `vendor/bin/pest packages/admin/tests/Feature/Filament/Resources/Page/Pages/ListPagesTest.php --filter="groups related page record actions"`

Expected: FAIL because `edit-layout` and `edit-blueprint` do not exist and Visit is top-level.

### Task 2: Add Page foreign-record actions

**Files:**
- Modify: `packages/admin/src/Filament/Resources/Pages/Tables/PagesTable.php:90-100`
- Modify: `packages/admin/src/Filament/Resources/Pages/Tables/PagesTable.php:532-539`

- [ ] **Step 1: Move Visit page into the existing action group**

Keep `EditAction::make()` as the sole primary record action. Make the existing group begin with:

```php
VisitUrlAction::make(),
```

- [ ] **Step 2: Add the contextual layout action**

Add a named Filament action within that group. It must only be visible for a Page with a Layout and use the configurable Layout resource route:

```php
Action::make('edit-layout')
    ->label(__('capell-admin::button.edit_layout'))
    ->icon('heroicon-o-rectangle-group')
    ->url(fn (PageModel $record): ?string => $record->layout instanceof Layout
        ? AdminSurfaceLookup::resource(ResourceEnum::Layout)::getUrl('edit', ['record' => $record->layout])
        : null)
    ->hidden(fn (PageModel $record): bool => ! $record->layout instanceof Layout),
```

Link `layout.name` with the same resolved URL, preserving its null-safe state.

- [ ] **Step 3: Add the contextual blueprint modal action**

Define an `EditAction::make('edit-blueprint')` within the group and configure it identically to the existing Blueprint table edit action: screen-large slide-over, Blueprint type heading, `type` raw-data mutation, Blueprint form schema, role-restriction update handling, and hidden state for a missing or trashed blueprint. The action must resolve the target Blueprint from `$record->type`, so editing it updates the Blueprint rather than the Page.

- [ ] **Step 4: Run the focused test and confirm it passes**

Run: `vendor/bin/pest packages/admin/tests/Feature/Filament/Resources/Page/Pages/ListPagesTest.php --filter="groups related page record actions"`

Expected: PASS.

### Task 3: Verify and commit

**Files:**
- Modify: `packages/admin/src/Filament/Resources/Pages/Tables/PagesTable.php`
- Modify: `packages/admin/tests/Feature/Filament/Resources/Page/Pages/ListPagesTest.php`

- [ ] **Step 1: Run focused Pages tests**

Run: `vendor/bin/pest packages/admin/tests/Feature/Filament/Resources/Page/Pages/ListPagesTest.php`

Expected: PASS.

- [ ] **Step 2: Run formatting and static analysis for changed files**

Run: `composer preflight`

Expected: PASS.

- [ ] **Step 3: Commit the implementation**

```bash
git add packages/admin/src/Filament/Resources/Pages/Tables/PagesTable.php packages/admin/tests/Feature/Filament/Resources/Page/Pages/ListPagesTest.php
git commit -m "feat: group related page record actions"
```
