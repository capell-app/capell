# Choose how to add a custom page field

A website can add its own content fields without changing Capell's source. Start with [Client name on a case-study page](../getting-started/add-a-custom-page-field.md) for a complete application-owned example. Use this page to choose a different editor, storage model or delivery contract.

Capell separates those responsibilities so an extension can contribute a field without owning the entire Pages resource. Your application can use the same contracts. That is why a small website-specific field does not need to become a Core feature.

The companion-extension source links below identify a fixed inspected commit and require access to the private source repository. The code examples here remain readable without that access; the public starter-kit example is linked separately. Check each extension's distribution and release status before depending on it.

## Choose the editor and the storage separately

| Approach                          | Use it when                                                                                             | What you own                                                                                         |
| --------------------------------- | ------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| Schema extender                   | Add fields to the existing page editor, including a translation hook, tab, sidebar or relation manager. | Scope, field schema and validation; storage is a separate choice.                                    |
| Application/package configurator  | A blueprint needs a different arrangement or a specialised editing workflow.                            | Configurator registration and the schema you customise; preserve other contributions where required. |
| Page metadata                     | A small page-wide value such as Client name needs no separate table.                                    | A namespaced JSON path, validation and safe output.                                                  |
| Translation metadata              | Editors need a different value for each language, such as a localised client credit.                    | The field inside the translation schema and locale-aware output.                                     |
| Typed properties                  | Values need reusable definitions, types, requirements, inheritance or machine-readable consumption.     | Property sets/definitions, attachment and an authoring UI or promoted source field.                  |
| Related application/package model | A Client has its own identity, several fields, relationships or independent lifecycle.                  | Tables, Actions, authorisation, relationships and downstream integrations.                           |
| Block, section or widget field    | The value belongs to one content component instance, rather than the whole page.                        | The optional component package's schema, input contract and renderer.                                |
| New page subject/model            | The content is a distinct domain subject with its own model and workflow.                               | A substantially broader integration than adding a field.                                             |

The first two rows assemble the editor. The following rows describe data ownership. A schema extender can edit page metadata, translated metadata or a related model; a configurator can use those same stores. Settings are another extension surface, but a site-wide default belongs in settings rather than masquerading as a per-page field.

## Add to an existing editor with a schema extender

The [walkthrough](../getting-started/add-a-custom-page-field.md) uses `AbstractPageSchemaExtender::extendTabs()`. Other supported hooks are `extendTranslationComponentsForHook()`, `extendSidebarComponents()` and `extendRelationManagers()`; register the class with `PageSchemaExtender::TAG`.

Keep a single quick field in the sidebar if it is useful alongside the content. Use a full tab for a larger group. Use a translation hook when each language owns its value. Preserve the input component arrays so independently registered extensions can coexist; avoid replacing another contribution merely to add your own field.

For a translated client credit, replace the walkthrough's `extendTabs()` method with the following method and imports in that extender:

```php
use Capell\Admin\Enums\PageTranslationSchemaHookEnum;
use Capell\Core\Models\Page;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Override;
```

```php
/** @return array<int, \Filament\Schemas\Components\Component> */
#[Override]
public function extendTranslationComponentsForHook(
    Schema $schema,
    PageTranslationSchemaHookEnum $hook,
): array {
    $record = $schema->getRecord();

    if (! $record instanceof Page
        || $record->blueprint?->key !== 'case-study'
        || $hook !== PageTranslationSchemaHookEnum::AfterTitle) {
        return [];
    }

    return [
        TextInput::make('meta.case_study.client_name')
            ->label(__('case-study.client_name'))
            ->required()
            ->maxLength(120),
    ];
}
```

Here the relative path is inside `TranslationsRepeater`, so it writes **Translation.meta**, unlike the top-level tab. Do not move a field between those locations without migrating its existing values. Resolve the current language's translation before rendering; declare Translation cache dependencies as well. [Schema hooks](../../packages/admin/docs/schemas/hooks.md) lists the available positions.

**Existing extension evidence:** Extension Cookbook's real sidebar contribution returns a package-owned component while leaving other schemas intact:

```php
return [Text::make(__('capell-extension-cookbook::admin.schema.help'))];
```

The [source class](https://github.com/capell-app/capell-packages/blob/00507db557838d94c633691872644db9f7a97300/packages/extension-cookbook/src/Filament/Extenders/ExtensionCookbookPageSchemaExtender.php) implements the same `PageSchemaExtender` contract as the walkthrough. Its presentation-only note can coexist with your persisted field; it does not own or save your client value.

SEO Suite's [page schema extender](https://github.com/capell-app/capell-packages/blob/00507db557838d94c633691872644db9f7a97300/packages/seo-suite/src/Filament/Extenders/Page/PageSeoSettingsTabExtender.php) illustrates extension-owned page editor contributions. Check the installed package's version and registration before copying its implementation.

## Give a blueprint its own configurator

Choose this when the whole editor shape should differ. A subclass in your application's namespace is a supported extension of the base configurator; it does not alter the vendor class.

Create `app/Filament/Configurators/Pages/CaseStudyPageConfigurator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Configurators\Pages;

use Capell\Admin\Filament\Configurators\Pages\DefaultPageConfigurator;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Override;

final class CaseStudyPageConfigurator extends DefaultPageConfigurator
{
    #[Override]
    protected function getEditFormSchema(Schema $schema): array
    {
        return [
            ...parent::getEditFormSchema($schema),
            Section::make(__('case-study.details'))->schema([
                TextInput::make('meta.case_study.client_name')
                    ->label(__('case-study.client_name'))
                    ->required()
                    ->maxLength(120),
            ]),
        ];
    }
}
```

Register it in your application's provider `boot()` method:

```php
use App\Filament\Configurators\Pages\CaseStudyPageConfigurator;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Facades\CapellAdmin;

CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::configurator(
    class: CaseStudyPageConfigurator::class,
    group: 'Pages',
    name: 'CaseStudy',
    owner: 'app',
));
```

Select **CaseStudy** in the case-study blueprint's Admin configurator setting. The stored selection is `admin.configurator = CaseStudy`. Rebuild the configurator cache as described in the walkthrough. Remove the alternative extender's Client name input if it is registered: two inputs bound to the same state are not two independent fields.

Calling the parent keeps its translation editor, publishing controls and extension pipeline. If you replace the whole schema, inspect those responsibilities explicitly. The configurator above adds the same Page metadata field, so the walkthrough's rendering code still applies.

**Existing extension evidence:** Blog's [ArticlePageConfigurator](https://github.com/capell-app/capell-packages/blob/00507db557838d94c633691872644db9f7a97300/packages/blog/src/Filament/Configurators/Articles/ArticlePageConfigurator.php) subclasses `DefaultPageConfigurator` to arrange an article editor; its [Admin provider](https://github.com/capell-app/capell-packages/blob/00507db557838d94c633691872644db9f7a97300/packages/blog/src/Providers/AdminServiceProvider.php) registers the configurator. The [starter kit case study](https://github.com/capell-app/starter-kit/blob/main/app/Filament/Configurators/Pages/CaseStudyPageConfigurator.php) is the smaller application-owned example.

## Store a simple value in metadata

`Page.meta` and `Translation.meta` are existing JSON columns. They are suitable for a short website-specific value that does not need a separate identity or relational query model. Use a namespace such as `case_study.client_name` to avoid collisions with another extension.

In an authorised application write Action, update the JSON without discarding unrelated keys:

```php
$meta = $page->meta ?? [];
data_set($meta, 'case_study.client_name', $validatedClientName);
$page->update(['meta' => $meta]);
```

`$page` is the already authorised, site-scoped Page and `$validatedClientName` is a validated string. The same pattern applies to an authorised Translation record. Protect concurrent writes through the application's normal locking/transaction strategy; a stale whole-JSON write can overwrite another edit.

Raw `$page->meta` reads local data. `getMeta()` can fall back to blueprint metadata for unfilled values. Decide whether inheritance is intended before substituting that helper. Never treat metadata as a secret store: delivery paths and installed API packages may expose selected metadata.

## Use typed properties for a structured contract

Core also supports `PropertySet`, `PropertyDefinition` and `PagePropertyValue`. Definitions can describe arbitrary custom values; schema.org semantics are optional. Choose this when several blueprints should share field definitions or when typed validation, completeness and agent consumption matter.

This one-time setup belongs in an application setup Action or data migration. It assumes authorised integer `$siteId` and `$pageId` inputs, and an existing case-study page:

```php
use Capell\Core\Actions\Properties\AttachPropertySetToBlueprintAction;
use Capell\Core\Actions\Properties\CreatePropertySetAction;
use Capell\Core\Actions\Properties\SetPagePropertyValuesAction;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\PropertyRequirement;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;

$blueprint = Blueprint::query()
    ->where('type', BlueprintSubjectEnum::Page)
    ->where('key', 'case-study')->firstOrFail();
$page = Page::query()->where('site_id', $siteId)
    ->where('blueprint_id', $blueprint->id)->findOrFail($pageId);
$set = CreatePropertySetAction::run([
    'key' => 'content.case-study',
    'name' => __('case-study.details'),
]);
$definition = $set->definitions()->create([
    'key' => 'client_name',
    'type' => PropertyType::Text,
    'requirement' => PropertyRequirement::None,
    'agent_visible' => false,
    'description' => __('case-study.client_name'),
]);
AttachPropertySetToBlueprintAction::run($blueprint, $set);
SetPagePropertyValuesAction::run($page, [new PropertyValueData(
    propertyKey: 'client_name',
    type: PropertyType::Text,
    value: 'Field Notes Studio',
)]);
```

The set creation rejects duplicate keys; do not run it on each request. Avoid duplicate unqualified definition keys among sets attached to the same blueprint. `PropertyValueData` takes `client_name`, not a qualified set/key string.

Read the value during application-side hydration, then pass the string to your render DTO:

```php
$clientName = $page->propertyValues()
    ->where('property_definition_id', $definition->id)
    ->whereNull('translation_id')
    ->value('value_text');
```

This raw read does not enforce publication or public visibility. Keep those checks in the public request/application boundary. A localised definition requires a translation belonging to the page. Sets are shared definitions; page values have page/site ownership.

### Keep an existing editor field as the source

Instead of writing the same value twice, attach the definition and promote the existing metadata field:

```php
use Capell\Core\Actions\Properties\PromoteBlueprintFieldAction;

PromoteBlueprintFieldAction::run(
    $blueprint,
    $definition,
    'meta.case_study.client_name',
);
```

Normal Page saves synchronise the mapped source into the typed property value. A null source is deliberately skipped, leaving its last typed value intact. If the source can be cleared, define and test that clearing policy before using the property as a publication requirement. Direct property writes are rejected for promoted definitions. Removing the mapping stops synchronisation and retains the last value; it does not migrate the editor field elsewhere.

The stock blueprint Properties tab attaches **existing** sets and edits per-property overrides. It does not create definitions or generate page value inputs. Supply a custom editor or promote an existing field. Use `PropertyRequirement::Publish` only when the value should gate publication; `Contract` affects completeness. Property writes take effect immediately rather than having independent draft and published copies.

Public agent resolution additionally requires a published page and `agent_visible`. JSON-LD generation requires a valid `schema:` semantic mapping; an unmapped Client name does not automatically appear there. See the [property write Action](../../packages/core/src/Actions/Properties/SetPagePropertyValuesAction.php), [promotion Action](../../packages/core/src/Actions/Properties/PromoteBlueprintFieldAction.php) and [public schema builder](../../packages/core/src/Actions/Properties/BuildPageSchemaGraphAction.php) for the exact contracts.

## Give richer data its own model

If a Client needs a logo, contact details, permissions or reuse across many case studies, model that domain in your application or extension. Do not repeat a large client object in every page's metadata.

For page-owned details, an application-owned `case_study_details` table can have a unique `page_id` and a `client_name` column. Create its migration, Eloquent model, fillable/cast rules and write Action in your application. Add a dynamic relationship without replacing Page:

```php
use App\Models\CaseStudyDetails;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Relations\HasOne;

Page::resolveRelationUsing(
    'caseStudyDetails',
    fn (Page $page): HasOne => $page->hasOne(CaseStudyDetails::class, 'page_id'),
);
```

In a blueprint-scoped extender tab, a Filament relationship section can edit that has-one record:

```php
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

Section::make(__('case-study.details'))
    ->relationship('caseStudyDetails')
    ->schema([
        TextInput::make('client_name')
            ->label(__('case-study.client_name'))
            ->required()
            ->maxLength(120),
    ]);
```

The bare `client_name` is valid here because the relationship section saves an attribute on **your** model. For reusable Client records, use a belongs-to relationship on your owned details model, an authorised selector, and a separate Client resource instead. Scope selectable records by the current page's site and the actor's permissions; the relationship registration does not provide that authorisation.

Use `extendRelationManagers()` for richer related-record workflows. Hydrate related data before Blade and register its cache dependencies. For revision capture/restore, implement `PageStateContributor`; for duplication, register the intended cloneable relations. Design deletion and export separately. A relationship alone does not make all those operations automatic.

**Existing extension evidence:** SEO Suite [registers dynamic Page relationships](https://github.com/capell-app/capell-packages/blob/00507db557838d94c633691872644db9f7a97300/packages/seo-suite/src/Providers/SeoSuiteServiceProvider.php) for snapshots and audit results. Layout Builder's [LayoutWidgetsPageStateContributor](https://github.com/capell-app/capell-packages/blob/00507db557838d94c633691872644db9f7a97300/packages/layout-builder/src/Support/Rollback/LayoutWidgetsPageStateContributor.php) demonstrates explicit related-state history integration.

## Put component-specific fields on their component

A testimonial's attribution, a hero's heading or a countdown's date belongs to that component occurrence if editors can insert several of them on the same page. Use the installed component package's contract rather than a single page-wide metadata value.

These are distinct optional surfaces:

| Surface          | Real implementation                                                                                                                                                                                                                                                                                                                                                                       | What the example proves                                                                                                             |
| ---------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| Block Library    | [AbstractCatalogBuilderBlock](https://github.com/capell-app/capell-packages/blob/00507db557838d94c633691872644db9f7a97300/packages/block-library/src/Filament/BuilderBlocks/AbstractCatalogBuilderBlock.php) and [HeroBlock](https://github.com/capell-app/capell-packages/blob/00507db557838d94c633691872644db9f7a97300/packages/block-library/src/Filament/BuilderBlocks/HeroBlock.php) | A Filament Builder block declares its own `title` and summary inputs. Its definition/content contract connects fields to rendering. |
| Content Sections | [HeroSectionConfigurator](https://github.com/capell-app/capell-packages/blob/00507db557838d94c633691872644db9f7a97300/packages/content-sections/src/Filament/Configurators/Sections/HeroSectionConfigurator.php)                                                                                                                                                                          | A section configurator adds fields such as `page_id` and `link_text` under the section's metadata.                                  |
| Content widget   | [CountdownWidget](https://github.com/capell-app/capell-packages/blob/00507db557838d94c633691872644db9f7a97300/packages/widget-countdown/src/Filament/CountdownWidget.php) and [CountdownInputData](https://github.com/capell-app/capell-packages/blob/00507db557838d94c633691872644db9f7a97300/packages/widget-countdown/src/Data/CountdownInputData.php)                                 | A Builder widget declares `title`, `timezone`, `targetUtc` and `expiredLabel`, with a typed input contract.                         |

For example, the Countdown widget's schema contains ordinary Filament fields:

```php
TextInput::make('title')->required()->maxLength(120);
```

Follow the owning package's registration, validation and rendering contract when adding your own component type. Content widgets and Layout Builder widget records are different surfaces. See [Build a page](../getting-started/building-pages.md), [widget registration](../frontend/widget-registration.md) and the installed package's README.

The source links above identify inspected implementations; they do not establish current release availability, compatibility or Marketplace access. Check those before selecting an optional dependency.

## Advanced model customisation

### A new subject is more than a field

`CapellCore::registerPageType(new PageTypeData(...))` registers a model subject that blueprints can target. It does not create a blueprint, add an input or confer Page's routing, authorisation, revisions and public API behaviour on an arbitrary model.

```php
use App\Models\CaseStudy;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Facades\CapellCore;

CapellCore::registerPageType(new PageTypeData(
    name: 'case-study',
    model: CaseStudy::class,
    label: __('case-study.details'),
));
```

Choose this only when you are integrating a separate domain subject and can own those contracts. An ordinary Page assigned to a Case study blueprint needs no new subject registration.

### Custom columns and model subclasses

Laravel allows an application migration to add columns, and Capell documents a container binding for an application-owned subclass:

```php
use App\Models\ExtendedPage;
use Capell\Core\Models\Page;

$this->app->bind(Page::class, ExtendedPage::class);
```

This is a targeted model integration, not an end-to-end custom-field mechanism. A column also needs the correct fillable/cast rules, editor schema and explicit revision/import/API support. Core's revision and import paths have attribute allowlists; arbitrary columns are not automatically included.

The binding affects `app(Page::class)` and other explicit container resolutions. It does not redirect static `Page::query()` calls to your subclass. Inspect every relevant caller before relying on it. Prefer metadata or your own related table when they meet the requirement; never edit a shipped migration or vendor model to add a website-specific field. See [Extending core models](../../packages/core/docs/extending-capell.md#8-extending-core-models).

## What integrates automatically?

This table describes the inspected foundation contracts and the named optional paths. Installed packages and API versions can change the result; test the application's chosen path before promising parity.

| Behaviour                  | Page metadata                                                                                                             | Translation metadata                                                                                                      | Typed properties                                                                            | Owned related records                                                             |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| Save and validation        | Page JSON persists through the ordinary form; the field owns its form rules.                                              | Translation JSON persists inside its translation form.                                                                    | The write Action validates attached definitions and types; supply an editor or promotion.   | Your model/schema/Action owns persistence and validation.                         |
| Revisions and rollback     | Captured in Page state when the write reaches `PageSaved`.                                                                | Captured with translations in the Page revision; a direct translation write must still trigger revision recording.        | Property values are captured/restored by the Page state serializer.                         | Implement a `PageStateContributor`.                                               |
| Duplication                | Normal Page replication copies stored attributes; verify the custom value in your scenario.                               | Replication copies fillable translation data and preserves metadata while changing the slug.                              | Do not assume relation copying; verify or integrate explicitly.                             | Register cloneable relations and test whether records should be cloned or shared. |
| Import/export              | Depends on provider. Migration Assistant's inspected Page path permits metadata; Admin's default exporter is unavailable. | Do not assume parity: the inspected Migration Assistant translation writer handles title/content, not arbitrary metadata. | No generic round-trip guarantee established here.                                           | Provide package-owned mapping and a round-trip test.                              |
| Public API                 | Explicit visitor-safe projection; no blanket inclusion.                                                                   | Endpoint/version-specific: optional legacy selectable metadata differs from the v1 PageData response.                     | Agent visibility and public JSON-LD have additional publication/semantic rules.             | Explicit visitor-safe projection.                                                 |
| Search                     | No automatic arbitrary-field indexing; configure the chosen search engine.                                                | Title/slug search does not imply searching all metadata.                                                                  | Use property queries when suitable; general search indexing remains a separate integration. | Add authorised queries/indexing.                                                  |
| Cache and export freshness | Ordinary Page saves enter invalidation; declare public render dependencies.                                               | Translation observers invalidate frontend data; declare the translation dependency.                                       | Verify the chosen consumer/cache path after property writes.                                | Register dependencies/observers and refresh affected HTML/static output.          |
| Removal                    | Removing code does not itself delete the JSON.                                                                            | The same; check remaining public consumers too.                                                                           | Plan definition, attachment and value retention explicitly.                                 | Own retention, cleanup and migration policy.                                      |

Relevant foundation sources: [PageStateSerializer](../../packages/core/src/EventSourcing/Serializers/PageStateSerializer.php), [RecordPageRevision](../../packages/core/src/EventSourcing/Listeners/RecordPageRevision.php), [ReplicatePageAction](../../packages/admin/src/Actions/ReplicatePageAction.php), [PageStateContributor](../../packages/core/src/EventSourcing/Contracts/PageStateContributor.php), [cloneable relations](../../packages/core/src/Concerns/HasCloneableRelations.php) and [cache invalidation](../performance/cache-invalidation.md).

Provider-specific evidence: Migration Assistant's [payload serializer](https://github.com/capell-app/capell-packages/blob/00507db557838d94c633691872644db9f7a97300/packages/migration-assistant/src/Services/Export/PayloadSerializer.php) and [page importer](https://github.com/capell-app/capell-packages/blob/00507db557838d94c633691872644db9f7a97300/packages/migration-assistant/src/Services/Import/PageImportService.php); API's [legacy payload builder](https://github.com/capell-app/capell-packages/blob/00507db557838d94c633691872644db9f7a97300/packages/api/src/Actions/BuildPublicPagePayloadAction.php) and [v1 PageData builder](https://github.com/capell-app/capell-packages/blob/00507db557838d94c633691872644db9f7a97300/packages/api/src/Actions/V1/BuildPageDataAction.php).

## Why this helps with upgrades

An extender registers a contribution at a named contract. A configurator lives in your namespace and can call the parent. A related model owns its own table. Those boundaries let Core receive upstream fixes while your customisation stays in your application or extension.

They still need compatibility checks: honour supported versions, mark real inherited overrides with `#[Override]`, and test registrations, saving, public output and enabled/disabled states after upgrades. Copying the Pages resource or publishing internal schemas makes your copy responsible for future upstream changes. Do not use schema publishing as a custom-field workflow.

The walkthrough's coexistence evidence demonstrates independent contributions within the capture fixture. The extension source examples demonstrate production package patterns. Neither is a promise that every combination of extensions works without integration testing.

## Bring your existing website

For an **existing Laravel application**, keep domain operations in its existing Actions/services and models where they belong. Add Capell's editing and page delivery around those boundaries. Decide which routes remain application-owned and which become Capell pages; use the [install guide](../getting-started/install.md), [package boundaries](../development/package-boundaries.md) and [frontend guide](../frontend/guide.md).

For a **migration from another CMS**, start with a content/data map and a URL inventory. A custom-field tutorial is not a migration tool. Follow the relevant [WordPress](../getting-started/coming-from-wordpress.md), [Statamic](../getting-started/coming-from-statamic.md) or [Voyager migration](../getting-started/voyager-migration/index.md) path, including its readiness status.

Before committing to either path:

1. Map each existing field to page metadata, translated data, a typed property, a component instance or a domain record. Identify private fields explicitly.
2. Preserve required addresses and redirects. Verify route ownership before enabling the public page route.
3. Define who can read/edit each field and which site owns it. Hiding a form component is not authorisation.
4. Connect the chosen Blade, Inertia or API frontend with explicit display data.
5. Prove publication, revisions, duplication and a real export/import round trip for the data you must retain.
6. Test cached output, search and integrations using representative existing content.
7. Record extension versions, availability, support and removal behaviour. Keep an export and a recovery plan before cutover.

## Next

- [Add Client name end to end](../getting-started/add-a-custom-page-field.md).
- [Extension point chooser](extension-point-chooser.md) for settings, tables, hooks and other extension jobs.
- [Build an extension](build-extension-end-to-end.md) when the feature should be reused.
