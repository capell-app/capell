# Admin Multi-Language

![Capell Admin Multi-Language screenshot](./images/screenshots/admin-pages-list.png)

Capell Admin can show the Filament admin panel in the language selected by each admin user. The language selector appears in the Filament user menu and the same preference can also be edited on the user resource.

This is an admin UI preference only. It does not change public frontend content language, site domains, page translations, or notification locale.

## How it works

Admin language preference uses three pieces:

1. **Capell language records** provide the selectable languages.
2. **Laravel translation files** provide the translated admin strings.
3. **User preference storage** stores the selected language on the user record.

The selector uses enabled `Capell\Core\Models\Language` records. When an admin chooses a language, Capell stores the selected record ID in `users.preferred_admin_language_id`.

On authenticated Filament admin requests, `SetAdminLocale` resolves that language to:

```php
$language->locale ?: $language->code
```

It then calls Laravel's locale APIs for the current request:

```php
app()->setLocale($locale);
app('translator')->setLocale($locale);
```

If the user has no preference, the language record is disabled, deleted, missing, or has an invalid locale value, Capell falls back to `config('app.locale')`.

## What the language record controls

The language record controls whether a language is available in the admin language selector.

The important fields are:

| Field    | Purpose                                                                 |
| -------- | ----------------------------------------------------------------------- |
| `name`   | Human label shown in the selector, for example `Français`               |
| `code`   | Short language code, usually ISO 639-1, for example `fr`                |
| `locale` | Laravel locale used for translations, for example `fr` or `pt_BR`       |
| `flag`   | Capell flag/icon identifier used by language UI                         |
| `status` | Must be enabled for the language to appear in the admin language select |
| `order`  | Sort order in language lists                                            |

Use `locale` for the exact Laravel translation directory name. If `locale` is blank, Capell falls back to `code`.

## Creating a new admin language

1. Add the language record in **Admin → Languages**.
2. Set `Name`, `Code`, `Locale`, `Flag`, and `Order`.
3. Enable the language.
4. Add Laravel translation files for the same locale.
5. Open the Filament user menu and choose the new language.

For example, to add French:

| Field    | Value      |
| -------- | ---------- |
| `name`   | `Français` |
| `code`   | `fr`       |
| `locale` | `fr`       |
| `flag`   | `fr`       |
| `status` | enabled    |

Then add translation files under the app's package override path:

```text
lang/vendor/capell-admin/fr/form.php
lang/vendor/capell-admin/fr/navigation.php
lang/vendor/capell-admin/fr/generic.php
lang/vendor/capell-admin/fr/notification.php
```

You do not need to copy every file at once. Laravel falls back through normal translation behaviour, but any missing Capell admin key will display in the fallback language or as the key depending on Laravel's fallback configuration.

## Translation file source

The built-in English files live in:

```text
packages/admin/resources/lang/en
```

In an application, override package translations in:

```text
lang/vendor/capell-admin/{locale}
```

For package development inside Capell itself, add translated files beside English:

```text
packages/admin/resources/lang/{locale}
```

Use the same file names and array keys as the English files. Keep user-facing strings behind translation keys rather than hard-coded in PHP, Blade, or Filament labels.

## Filament translation files

Capell Admin also renders Filament's own UI strings, such as login, table, form, and layout text. Filament ships translations for many locales. If a locale is not complete in Filament, Capell package strings may translate while Filament chrome falls back.

For host app overrides, use Laravel's vendor translation paths for the relevant Filament packages, for example:

```text
lang/vendor/filament-panels/{locale}
lang/vendor/filament-actions/{locale}
lang/vendor/filament-forms/{locale}
lang/vendor/filament-tables/{locale}
```

Only add these when the installed Filament packages do not already provide the locale or when the project needs custom wording.

## User preference controls

Admins can change their own language from the Filament user menu. The selector posts to:

```text
POST /admin/profile/language
```

The user resource also includes an **Admin Language** field when `users.preferred_admin_language_id` exists. Admins who can edit a user can set that user's admin language there.

Both controls use the same persistence action, so the preference behaves consistently.

## Troubleshooting

If a language does not appear in the menu:

- Confirm the `Language` record is enabled.
- Confirm it has a valid `locale` or `code`.
- Confirm the Capell migration added `users.preferred_admin_language_id`.

If the menu changes but the UI stays in English:

- Confirm the translation files exist for the language's `locale`.
- Confirm the file names and keys match the English files.
- Confirm Laravel's config/cache has been cleared after adding files.

If only some text changes:

- Capell admin translation files may be present, but Filament vendor translation files may be missing.
- Some optional packages may have their own translation namespace and need their own `{locale}` files.
