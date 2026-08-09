# Adding a Language to a Live Site

This is a task-ordered walkthrough for editors. It covers what happens to a live site the moment a language is added, how to translate pages, and how to read the badges the admin shows you.

Read the section **What visitors see immediately** before you add a language to a site that is already public. The behaviour surprises people.

## Before you start

You need:

- Permission to manage languages and to edit pages.
- A decision about how the new language will appear in URLs — a path prefix (`example.com/fr`) or a separate subdomain or domain. The default is a path prefix.
- Somewhere to get the translations from. Capell does not translate anything for you unless auto-translate is configured (see [Auto-translate](#auto-translate)).

## Step 1 — Add the language record

1. Go to **Admin → Languages**.
2. Choose **New**.
3. Fill in:
    - **Name** — the label editors and visitors see, for example `Français`.
    - **Code** — the short language code, usually ISO 639-1, for example `fr`.
    - **Locale** — the locale used for translation files and date formatting, for example `fr` or `pt_BR`.
    - **Flag** — the flag icon used in language tabs and switchers.
    - **Order** — the position in language lists.
4. Under **Language availability**, enable **Status**. A disabled language does not appear anywhere.
5. Leave **Right to left** alone unless you need to override it. Capell already renders Arabic, Hebrew, Persian, Urdu and similar languages right to left without being told. The toggle exists for the exceptions, and an explicit **off** wins over Capell's built-in list.

## Step 2 — Set the language up on your sites

On the create form there is a **Language site setup** section. Tick **Set up this language** and select the sites it should be added to. The same fan-out is available from the row actions when you replicate an existing language.

For each selected site, Capell:

- creates a translation row for the site itself, and
- creates a site domain row for the new language — a copy of the site's existing domain with the path set to `/{code}`, for example `/fr`.

You can adjust that domain afterwards under **Settings → Sites** if you want a subdomain or a separate domain instead of a path prefix.

## What visitors see immediately

**As soon as the language is set up on a site, its URLs are live and they serve your default-language content.**

`SetupSiteLanguageAction` creates the new translation row by **copying the default-language row**. It does not create a blank row. So the moment `/fr` exists, a visitor going to `/fr` sees your English text, at a French URL, with `<html lang="fr">`.

There is no draft state for this. Plan the work so the gap between adding a language and translating your key pages is short, and do not announce or link the new URLs until the translation is done.

This also explains the next section.

## Why a brand-new language can look 100% complete

The completeness percentage on each language tab counts **blank fields**. It compares each tracked field in the translation against the same field in the default language, and reports the proportion that are filled in.

A row that was cloned from the default language has nothing blank in it. It is full of English. So by that measure it is 100% complete — and it has not been translated at all.

Capell tries to catch exactly this case. When a row is 100% complete _and_ every tracked field still matches the default language byte for byte, the badge reads **Copied from default** in grey rather than **Complete** in green. That is a strong signal, but it is not a guarantee: as soon as you edit a single field, the row stops matching exactly and the badge switches to **Complete**, even though the rest of it is still English.

Treat the percentage as a _blank-field counter_, nothing more. It says nothing about whether the text is correct, whether it is in the right language, or whether it is up to date.

## Step 3 — Translate a page

1. Open the page in **Admin → Pages**.
2. The page editor shows one **tab per language**, labelled with the language name and flag. The default language is first.
3. Select the tab for the new language and overwrite the content.
4. Save.

If a page does not yet have a tab for the language, use **Add translation** and pick the language from the list. Only enabled languages that the page does not already have are offered.

### Copy, then overwrite

The **Clone** action on a language tab duplicates that tab into a new language. This is the intended workflow: copy the finished default-language version so the structure, blocks and images are already in place, then replace the text.

It is the same mechanism as the automatic clone in step 2, done deliberately and one page at a time.

### Auto-translate

The **Auto translate language** button fills a tab from another language using an external translation service.

It is **disabled until translation API credentials are configured**. When they are missing, hovering the button shows a tooltip explaining what is needed — a `GOOGLE_TRANSLATE_API_KEY`, a `YANDEX_TRANSLATE_API_KEY`, or a custom API translator. Ask whoever administers the installation; this is not something an editor can set from the admin.

Auto-translate overwrites the target tab. Treat its output as a draft to be reviewed.

## Reading the badges

Each non-default language tab carries a badge. The default language never has one — there is nothing to compare it against.

| Badge                   | Colour         | Meaning                                                                                         |
| ----------------------- | -------------- | ----------------------------------------------------------------------------------------------- |
| **Complete**            | green          | Every tracked field is filled and the default language has not been edited since you last saved |
| **Copied from default** | grey           | Every tracked field still matches the default language exactly — almost certainly untranslated  |
| `72%`                   | blue/amber/red | That proportion of tracked fields is filled compared with the default language                  |
| **May be outdated**     | amber          | The default language was edited after this translation was last saved                           |

### The staleness badge is deliberately coarse

**May be outdated** means one thing only: the default-language translation row has a newer `updated_at` than this one.

It cannot tell you which field changed, and it does not try. Page content is stored as **one JSON document per language**. There are no per-field timestamps to compare. Any edit to the default language — a typo fix in one paragraph, a changed image caption — updates the whole document, and therefore flags _every other language_ on that page as possibly outdated.

So the badge is a prompt to look, not a verdict. Expect false alarms after small edits. The alternative would be to say nothing when content really has diverged, which is worse.

## Finding what still needs work

Two tools help you work through a backlog.

**The untranslated filter.** On **Admin → Pages**, the **Untranslated into** filter takes a language and shows pages that have no usable translation for it — either no translation row at all, or a row with both an empty title and empty content.

Note what this filter does _not_ catch: a row cloned from the default language has a title and content, so it counts as translated and will not appear. Use the **Copied from default** badge on the page itself for that case.

**The translations export.** On **Admin → Languages**, the **Export translations** header action downloads a CSV for a chosen site, either for one target language or for all of them. Each row is one record and one target language, with the source and target title, content and metadata side by side. Rows are included for target languages that have no translation yet, with the target columns blank — so the file doubles as a work list you can hand to a translator.

Blueprint content is exported as the stored JSON document, not split into individual fields. The export is read-only; there is no matching import.

## Which strings live where

This naming collision genuinely catches people out. There are two separate things called "translation" in a Capell admin, they share no storage and no code path, and neither one can reach the other.

| You want to translate…                                                       | Where                                                                         |
| ---------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| Page content — headings, body copy, page titles, page metadata               | The **language tabs** on each page, described above                           |
| Interface text — admin labels and buttons, theme strings, error page wording | **Translation Manager**, a separate package that edits Laravel language files |

Translation Manager is a file-based editor for the `lang/` files in your application and its installed packages. It compares a source and a target locale side by side, shows missing and stale keys, and writes package strings to Laravel's override paths so upgrades do not clobber them. It creates no database tables.

Translation Manager is a **free** package in the Capell Publishing group. If it is not installed, interface text can still be translated by adding language files by hand — see [Admin Multi-Language](admin-multi-language.md).

Translating every page into French does not translate the word "Search" in your theme. Translating your theme's language file does not translate a single page.

## Before you switch visitor language detection on

**Settings → Frontend → Visitor language detection** decides what happens when someone arrives on a page written in a language their browser says they do not read. It has three modes: **do nothing** (the default), **suggest with a dismissible banner**, and **redirect to the same page in their language**.

**Leave it off until your key pages are actually translated.**

Remember what step 2 did: the new language's pages already exist, and they contain English. If you switch detection on before you have translated them, you will send a French-speaking visitor to a French URL that is still showing English — and you will have taken away their ability to notice they were on the English page in the first place.

When you do turn it on, the banner mode is the safer choice. It suggests rather than decides, and it carries none of the search-engine caveats that automatic redirection does. The full behaviour, including the caveats on redirect, is described in [Multi-site and Multi-lingual](../../core/docs/multi-site-multi-lingual.md#visitor-language-detection).

## Checklist

1. Create the language record and enable it.
2. Set it up on the sites that need it — the URLs go live immediately, showing default-language content.
3. Translate your key pages: home, navigation targets, contact, anything you advertise.
4. Work through the rest using the **Untranslated into** filter and the CSV export.
5. Translate the interface strings separately, with Translation Manager or by adding language files.
6. Only then consider turning on visitor language detection.

## Further Reading

- [Admin Multi-Language](admin-multi-language.md) — Languages CRUD, the translations repeater, admin interface language
- [Multi-site and Multi-lingual](../../core/docs/multi-site-multi-lingual.md) — URL structure, hreflang, sitemaps, locale and the HTML cache
