# Capell Agent Schema

Capell Core exposes a small, anonymous-safe contract for agent discovery and
read access. This document describes the shipped foundation in the 26 August
2026 draft of the Capell Agent Schema. It is an application contract, not a
claim that WebMCP is a ratified W3C standard.

## What is public

Public tools are read-only unless their declaration explicitly says
`effect: write`. Core currently provides page lookup from the page's inline
payload, published page property queries, taxonomy browsing, search, and the
published site map. The public API uses the `/agent/v1` namespace and resolves
the current site and language from the same host and domain rules as anonymous
page delivery.

Every public page result is filtered by site, language, URL status, enabled
domain, published visibility, and an enabled and accessible page blueprint.
Drafts, scheduled pages, redirects, missing translations, and private page
types are not public results. Responses use a version marker:

```json
{
    "capellAgentSchema": 1,
    "data": [],
    "links": { "next": null, "previous": null }
}
```

The read endpoints are paginated and bounded by their request validators.
The page API includes all agent-visible properties: schema.org mappings use the
semantic key, while unmapped properties use `capell:<set>.<property>`. References
still pass public site, publication and URL checks; storage ids are never returned.
Property queries allow only agent-visible definitions and indexed SQL
constraints. They do not execute code supplied in a request.

## Property data

Property sets are keyed, typed records. A definition may be scalar, localised,
reference-valued, money, duration, or another Core-supported property type.
References are projected as typed ids or URLs, money retains its currency, and
duration retains its unit. Publication has one current public copy: draft
revisions are not projected into the public schema or read API.
Public projection honours the same precedence rules as page authoring:

1. a value stored on the page wins;
2. when no page value exists, the winning assigned term value is inherited;
3. taxonomy position, term assignment position, term id, and value position
   provide deterministic tie-breakers.

Inheritance is scoped to the page's site. A value from a term or taxonomy on a
different site can never enter the result. Localised values select the requested
translation and fall back only according to the Core value rules. A draft or
unpublished page is never projected by the public query.

## Property rules

A definition can carry declarative rules in `property_definitions.constraints`,
stored as versioned JSON (`{"version": 1, ...}`):

| Rule                                                 | Applies to                              | Meaning                                                                    |
| ---------------------------------------------------- | --------------------------------------- | -------------------------------------------------------------------------- |
| `min`, `max`                                         | money, number, dimension, duration      | Inclusive numeric range.                                                   |
| `min_length`, `max_length`                           | text, URL                               | Length in characters.                                                      |
| `pattern`                                            | text, URL                               | Format the whole value must match, written without delimiters or anchors.  |
| `unique_per_site`                                    | number-like, text, URL, entry reference | No other page on the site may hold the same value.                         |
| `allowed_blueprints`                                 | entry reference                         | Page type (blueprint) keys the reference may point at.                     |
| `allowed_taxonomies`                                 | term reference                          | Taxonomy keys the term must belong to.                                     |
| `allowed_mime_types`                                 | media                                   | File types such as `image/png`, or `image/*`.                              |
| `min_width`, `max_width`, `min_height`, `max_height` | media                                   | Image size in pixels; images of unknown size are refused while one is set. |

`BuildPropertyValidationRulesAction` turns these into one rule set, and
`PropertyValueValidator::assertConstraints()` applies it on every write path:
`SetPagePropertyValuesAction` (and so SiteSpec imports and confirmed agent
writes), the `admin.page.properties.write` preview, the admin page editor for
promoted fields, and any page save that changes a promoted field, checked
before the row is written. A rejected value raises a `ValidationException` with
the same editor-readable message on each path.

A value a write changes is always checked: every promoted field on a new page,
a promoted field whose value changes, and every property value written with a
new value. Moving a page to another blueprint or site checks every promoted
field against the rules that will then apply, in production too, because the
value is new to those rules. A promoted value counts as unchanged when it is
the same once stored as the property's type (`5.0` over a saved `5`), and the
page editor and page saves share that one definition
(`PromotedFieldChanges`). Checks run
before anything is written, so a refused save never leaves a page half-saved.

A value the write leaves as it was may predate a rule. What happens then depends
on the environment (`PropertyValueValidator::enforcesUntouchedValues()`, true
unless `app()->isProduction()`):

- Outside production (local, testing, staging) it is enforced like a changed
  value, so developers find over-limit data as soon as a rule is added.
- In production it is reported and the save goes through, so a rule added later
  never blocks an unrelated edit. The page editor shows a warning naming the
  field and the broken rule, and the save logs one warning per breach with
  identifiers only, never the value. The same rule covers an agent preview of
  an unchanged value, a value repeated at a stored entry the write leaves in
  place, and values a revision rollback restores (a refusal rolls the whole
  restore back). The promoted property is not re-synced until someone
  corrects the field.

Page saves only look further when the blueprint has a promoted field whose
property carries rules, and a property write only looks up the stored value for
a definition with rules.

Uniqueness is checked against live pages on the same site, and a value
repeated across one page's entries is refused, whether both entries are in the
same write or one is already stored at a position the write leaves in place. A page in the
bin does not hold its values, so restoring it is refused while another page now
uses one of them. Switching `unique_per_site` on is refused while pages on one
site already share a value. The check runs before the write without a database
unique index, so two simultaneous saves of the same value can both succeed;
treat uniqueness as an editorial guard, not a key.

Format patterns run with a capped backtracking budget. A pattern that cannot
finish within it on short probe strings, such as `(a+)+`, is refused when it is
saved, and a stored pattern that runs out of budget fails the check rather than
passing it. A pattern must also keep matching the whole value once it is
anchored: one that does not compile on its own, such as `x)|(y`, or that uses a
backtracking control verb such as `(*ACCEPT)`, is refused when saved, and the
same check runs before every match, so a pattern stored by another path fails
closed.

Administrators set rules from the blueprint list (**Field rules**). Rules belong
to the definition, so they apply on every page type that uses its property set.
`UpdatePropertyDefinitionConstraintsAction` saves them, refusing rules that
cannot apply to the type, upside-down ranges and patterns that do not compile.
Package-owned sets are managed by their package, which writes `constraints`
when it syncs its own definitions; the admin action refuses them. In tests, use
`PropertyDefinition::factory()->withConstraints([...])`. Blueprint schema
snapshots record each attached definition's type, requirement and rules.

## Tool declarations

An extension can declare a public tool in `capell.json` under `agent_tools` or
through a trusted PHP contribution. Declarations contain a stable lowercase
dotted name, a provider-authored description or translation key, input and
output JSON Schemas, a read/write effect, and a declarative binding:

```json
{
    "name": "catalogue.lookup",
    "descriptionKey": "vendor-catalogue::agent.lookup",
    "inputSchema": { "type": "object", "additionalProperties": false },
    "outputSchema": { "type": "object" },
    "effect": "read",
    "binding": { "type": "endpoint", "target": "/agent/v1/catalogue/lookup" }
}
```

Bindings are data, never callbacks or executable source. Endpoint targets are
same-origin paths; form targets are stable DOM ids; search and property-query
targets are stable identifiers. The inline binding is reserved for Core's
`page` payload. The normaliser rejects unsupported schema fields, foreign URLs,
fragments, selectors, and arbitrary handlers.

Trusted PHP providers may use the attribute helper:

```php
#[AgentTool(
    name: 'catalogue.lookup',
    descriptionKey: 'vendor-catalogue::agent.lookup',
    inputSchema: ['type' => 'object', 'additionalProperties' => false],
    outputSchema: ['type' => 'object'],
    effect: AgentToolEffect::Read,
    bindingType: AgentToolBindingType::Endpoint,
    bindingTarget: '/agent/v1/catalogue/lookup',
)]
final class CatalogueLookupTool implements DefinesAgentTool
{
    use HasAgentToolDefinition;

    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
```

The attribute only derives a typed definition. It does not authorise a route,
invoke a handler, or grant access. The package must provide the existing action,
form, search, or property-query integration named by the binding.

An interactive public surface that has no declaration produces an audit warning
in the first mandate release. A package may use `agent_tools: none` with a
human-readable reason for an intentionally non-actionable surface. The audit
can be configured to fail as an error for stricter environments. “Agent-ready”
means the declaration and its binding pass validation and audit; it does not
mean that every browser implements WebMCP or that a write action is invoked
without user mediation.

## Navigation, forms, and admin tools

`site.navigation` returns URL-addressable published pages from Core's public
site map. Navigation packages may add richer menu structures through their own
public declaration and existing frontend route.

Write tools use existing public forms and the browser's user-mediation flow.
They must declare `effect: write`; Core does not auto-submit them. CAPTCHA,
spam controls, and bot policy remain the site's responsibility.

Authenticated admin tools use a separate registry and the existing session,
policy, and permission boundary. Content reads, property writes, term assignment,
scratch drafts, publication, and structure/settings operations use the existing
domain Actions. Writes require a one-use confirmation bound to the actor, site,
session, payload and preview; confirmation rechecks authorisation and state.
Global property sets, blueprints and settings require global-admin capability.
Admin declarations and confirmation tokens never enter public HTML or the
anonymous registry.

## Current limits

The current contract covers published public reads and declarative discovery.
Draft projection into public data, arbitrary agent code execution, automatic
write invocation, and full authenticated admin parity remain unsupported. Browser feature
detection is expected to degrade silently; server APIs and the inline payload
remain useful when `navigator.modelContext` is unavailable.
