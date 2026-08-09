# Multi-site and Multi-lingual

Capell can run multiple websites and multiple languages from a single installation. Both features are built into the core and work together: each site can have its own set of languages, and every piece of content is translatable.

For the canonical visual orientation to Core records, use the [Core overview](overview.md#screens-and-workflow). This guide stays focused on site and language behaviour.

---

## Overview

A **site** in Capell is a distinct website with its own pages, navigation, theme, and settings. You might use multiple sites to manage:

- Separate domains for different brands or regions (`brand-a.com`, `brand-b.com`)
- A main site and a subdomain (`example.com`, `shop.example.com`)
- Path-based site partitions (`example.com/site-a`, `example.com/site-b`)

A **language** defines a locale (e.g. `en`, `fr`, `de`) with its own URL prefix or domain, and its own translations for all page content, navigation, and metadata.

---

## Setting Up Multiple Sites

### Create a site

1. In the admin panel, go to **Settings → Sites**.
2. Click **New Site** and fill in the name, domain, and theme.
3. Configure the site's default language.
4. Create pages and navigation specific to that site.

### Site detection

Capell detects which site a request belongs to by matching the incoming domain against the site's configured domains. Two strategies are supported:

- **Domain-based:** `example.com` → Site A, `another-site.com` → Site B
- **Path-prefix-based:** `example.com/site-a` → Site A, `example.com/site-b` → Site B

You can mix both strategies across sites in the same installation.

### Default site redirect

When a request comes in for an unknown domain, Capell can redirect it to the default enabled site. This behaviour is controlled by:

```php
// config/capell-frontend.php
'redirect_default_site' => true,
```

---

## Setting Up Multiple Languages

### Add a language

1. Go to **Settings → Languages**.
2. Add a language with its code (e.g. `en`), locale (e.g. `en_GB`), and flag.
3. Assign the language to one or more sites.

### Language detection

Capell resolves the language from the request URL. Supported strategies:

- **Subdomain:** `en.example.com`, `fr.example.com`
- **Path prefix:** `example.com/en`, `example.com/fr`

The language is a property of the resolved **site domain** row, not of the request. Two consequences follow from this and they are worth stating plainly, because most of the behaviour below is derived from them:

- The same URL always resolves to the same language, for every visitor, on every request.
- Request headers — `Accept-Language` in particular — never change which language a URL renders in.

### Applying the locale

Once the site domain is resolved, `ApplyLocaleStep` pushes the language's `locale` (falling back to its `code`) into the application for the rest of the request. `FrontendLocaleScope` sets:

- the application locale (`app()->setLocale()`)
- the translator locale, so `__()` and `@lang` in themes and packages resolve against the site's language
- `Carbon` and `CarbonImmutable`, so dates, month names and relative times render in the site's language

The previous locale is restored when the request terminates, so a long-running worker (Octane, queue-served requests) cannot bleed one site's locale into an unrelated later request.

Only locales matching `[A-Za-z0-9_-]+` are applied. A language record with a malformed `locale` is ignored rather than used to build a translation file path.

### Text direction

`Capell\Core\Models\Language::direction()` returns `ltr` or `rtl` and drives the `dir` attribute on `<html>` across the frontend and theme render paths.

It resolves in this order:

1. The language's `meta.rtl` toggle, if it has been set explicitly in the admin. An explicit **off** wins, even for a language that would otherwise be treated as right-to-left.
2. Otherwise, the root subtag is matched against `Language::RTL_ROOT_LANGUAGES` (`ar`, `fa`, `he`, `ur`, `ku`, `ps`, and others). Matching is on the root subtag, so `ar-EG` resolves like `ar`.
3. Otherwise, `ltr`.

Direction is derived per language, not per site. A site with both English and Arabic serves `dir="ltr"` and `dir="rtl"` from the same theme.

### Translating content

Every page, navigation item, and media file has a translation for each enabled language. To translate a page:

1. Open the page in the admin editor.
2. Use the language switcher to change to the target language.
3. Fill in the translated title, slug, and body.
4. Publish the translation independently (a page can be live in English but still in draft for French).

---

## Visitor Language Detection

A visitor can land on a URL written in a language they do not read — from a search result, a shared link, or a bookmark. **Settings → Frontend → Visitor language detection** controls what happens next. It is a single setting for the installation, stored as `visitor_language_detection` in the `frontend` settings group.

| Mode       | Behaviour                                                                             |
| ---------- | ------------------------------------------------------------------------------------- |
| `off`      | Nothing happens. `Accept-Language` is ignored entirely. **This is the default.**      |
| `banner`   | The visitor stays where they are and is offered a dismissible suggestion to switch.   |
| `redirect` | A first-time visitor is sent to the exact translation of the same page, with a `302`. |

### Which mode to choose

`banner` is the recommended mode for most sites. It gives the visitor the same information without taking the decision away from them, and it is the mode with the fewest caveats.

Use `redirect` only when you know the audience is strongly segmented by language and you have accepted the two caveats below.

Leave the setting `off` until your key pages are genuinely translated. A newly added language starts as a copy of the default language (see [Adding a language to a live site](../../admin/docs/adding-a-language-to-a-live-site.md)), so switching detection on too early sends visitors to English content sitting at a foreign-language URL — a worse outcome than not reacting at all.

### SEO caveat on `redirect`

Google's own guidance is to **prompt** rather than automatically redirect: an automatic redirect based on `Accept-Language` can prevent a crawler from ever reaching some language variants of your site, because the crawler's stated language is not the visitor's. Capell mitigates this — crawlers are excluded from redirection by user-agent, and the redirect is `302` rather than `301` — but the risk is inherent to the technique, not to the implementation. `banner` mode carries no such caveat.

### CDN caveat on `redirect`

Redirection is best-effort behind a CDN. The redirect is issued by middleware at the origin; a request served from a warmed edge cache never reaches the origin at all, so no redirect is issued. Treat `redirect` as something that happens on cache misses, not as a guarantee. `banner` mode is unaffected, because the suggestion is decided client-side from a cached page.

### What detection will never do

- It only ever offers the **exact translation of the same page**. If the page the visitor asked for has no translation in their language, nothing happens; they are never bounced to a language homepage.
- It reacts once. The `capell_lang` cookie records that the visitor has decided — by being redirected, or by using the theme's language switcher — and detection never re-evaluates afterwards.
- It only reacts on an entry navigation (judged from `Sec-Fetch-Site`, falling back to a same-host `Referer`). A cookie-less browser clicking around the site is not bounced on every internal link.
- It only reacts to `GET`, and never to a request with no stated `Accept-Language` preference.

### Locale and the HTML cache

This is the constraint the whole design is built around, and it matters if you extend the frontend.

**The public HTML cache keys on host + path alone.** The rendered bytes for a URL are therefore shared by every visitor who asks for that URL. Locale must remain a pure function of host + path, or one visitor's cached page is replayed to everybody else.

That is why detection may only:

- **redirect**, before the cache middleware runs — the cache never sees a request whose response depends on a header — or
- **show a banner**, whose variants are baked into the cached bytes and selected client-side from an unencrypted cookie.

It must never vary the rendered response by request header. In particular, the pass-through response is left completely untouched: `Vary: Accept-Language` and `Cache-Control: no-store` are set on the `302` only, which is never cached. Adding a `Vary` header to the pass-through response would poison the shared cache entry for that page.

If you add locale-dependent behaviour of your own, apply the same rule: derive it from host + path, or resolve it client-side.

---

## URL Structure

Capell builds hierarchical URLs from the page tree, using each page's slug for the active language:

| Pattern                        | Example URL                   | Description                       |
| ------------------------------ | ----------------------------- | --------------------------------- |
| Default site, default language | `example.com/about/team`      | No prefix                         |
| Path-prefixed language         | `example.com/fr/about/equipe` | Language prefix + localized slugs |
| Subdomain language             | `fr.example.com/about/equipe` | Subdomain + localized slugs       |
| Separate domain, site B        | `autre-site.com/contact`      | Different domain                  |

When a parent page's slug changes in any language, Capell automatically rebuilds all descendant slugs for that language and records the old paths as 301 redirects.

---

## SEO

### Hreflang tags

Capell generates `hreflang` link elements in the `<head>` for every published language variant of a page, and tells search engines which language version to show to which users.

A variant is only listed when all of the following hold: the page URL is a canonical URL rather than a redirect, it is enabled, and the page actually has a translation row for that language.

**A cluster is only emitted when at least two variants qualify.** A single-language site therefore emits no `hreflang` markup at all. This is deliberate: a self-referencing cluster of one tells a search engine nothing, and an incomplete cluster is worse than none.

When the site's own default language is among the qualifying variants, it is also emitted as `hreflang="x-default"`.

### Open Graph locale

`og:locale` is derived from the **served site language**, not the application default locale, and is normalised towards Open Graph's `ll_CC` form: `en_GB`, `pt_BR`. A language with no region (`fr`) is emitted as the bare subtag rather than being given an invented region. A `locale` value that cannot be normalised falls back to the language `code`; if neither is usable, the tag is omitted rather than guessed.

The other qualifying language variants of the same page are emitted as `og:locale:alternate`. The primary locale is never repeated as one of its own alternates.

`og:locale` requires the `capell-app/seo-suite` package.

### Language-aware sitemaps

Each site+language combination gets its own XML sitemap entry. Sitemaps include `<xhtml:link rel="alternate">` entries pointing to the other language variants of each page, under the `xmlns:xhtml` namespace.

Alternates use the same qualification rules as the `<head>` tags — canonical, enabled, and backed by a real translation row — and the same two-variant minimum: a single-language site's sitemap carries no alternates and no `xhtml` namespace declaration. Where the site's default language qualifies, an `x-default` alternate is appended.

Sitemaps are provided by the `capell-app/site-discovery` add-on.

### Dates, numbers and interface strings

Because the resolved site language is applied to the application locale, the translator and Carbon for the duration of the request, `__()` calls, published dates, month names and relative times on a public page render in that site's language rather than in the application default. This applies to theme strings and to package-provided frontend strings, including the error pages under the `capell-frontend::errors.*` namespace.

The strings themselves still have to exist. Capell ships English (`en`) language files; any other language needs its own files under Laravel's vendor override paths before the localisation is visible. See [Admin Multi-Language](../../admin/docs/admin-multi-language.md) for where those files live.

### Canonical URLs

The canonical URL for each page is generated based on the active site domain and language. If a page appears under multiple paths (e.g. after a URL change), only the current canonical is included.

---

## Performance

- Each site+language combination is cached separately. Changing an English page does not purge French cache entries.
- Language and site resolution happens at the very beginning of the request pipeline (the `SiteResolveStep`). All subsequent processing — page resolution, layout loading, caching — is scoped to the resolved site and language.
- Translations are loaded lazily only when the relevant language context is active.

---

## Further Reading

- [Adding a language to a live site](../../admin/docs/adding-a-language-to-a-live-site.md) — the editor-facing walkthrough
- [Admin Multi-Language](../../admin/docs/admin-multi-language.md) — Languages CRUD, translations repeater, admin interface language
- [Page Management](page-management.md) — URL history, redirects, slug management
- [Content Management](content-management.md) — translating content, per-language publishing
- [Page & Site Loading](../../frontend/docs/page-site-loading.md) — how site/language resolution works internally
- [Sitemaps](https://docs.capell.app/sitemaps/) — XML sitemap generation and serving (in the `capell-app/site-discovery` add-on)
- [Packages and extensions](../../../docs/packages/catalog.md) — host package boundaries and extension documentation entry points
