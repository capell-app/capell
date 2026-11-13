# Privacy consent

Every public page defines `window.CapellPrivacy` before any theme or widget script runs. Ask it before you load an embed, set a cookie or send an analytics or marketing signal. A site without a consent manager gets Capell's default runtime; a consent manager such as Privacy Center replaces it with one that records visitors' choices. Your code works the same with either.

## Ask before loading

`CapellPrivacy.allows(name)` takes a category or a named purpose and returns:

| Value   | Meaning                                       | What to do                                  |
| ------- | --------------------------------------------- | ------------------------------------------- |
| `true`  | The visitor, or the site's default, grants it | Load or send.                               |
| `false` | Refused, or refused by an opt-out signal      | Do not load or send.                        |
| `null`  | Nobody has decided, or the name is unknown    | Do not send analytics or marketing signals. |

The categories are `essential`, `functional`, `preferences`, `analytics` and `marketing`. Essential is always `true`.

```js
if (window.CapellPrivacy?.allows('analytics') === true) {
    sendPageView()
}
```

Treat `null` as refused for anything that tracks a visitor. Only `true` sends a signal.

The stable API is these four members, which every runtime provides:

| Member               | Returns                                                                                      |
| -------------------- | -------------------------------------------------------------------------------------------- |
| `allows(name)`       | `true`, `false` or `null`, as above.                                                         |
| `consent()`          | The visitor's choice as `{ id, version, granted }`, or `null` before they choose.            |
| `choose(categories)` | A promise of `true` once the granted categories are recorded, or `false` if they were not.   |
| `open()`             | Nothing; it shows the consent manager's choices, and does nothing without a consent manager. |

Any other property you find on `window.CapellPrivacy` is internal to that runtime and can change without notice.

When a choice changes, the runtime dispatches `capell:privacy-consent-changed` on `document` with the new `consent()` value as `detail`. The default runtime keeps a `choose()` choice for the current page only: it stores nothing, so the next page starts from the site's defaults.

## Gate markup without writing JavaScript

Mark a script or frame with `data-capell-consent` and the runtime loads it once its purpose is granted:

```html
<script
    type="text/plain"
    data-capell-consent="analytics"
    data-type="module"
    src="/analytics.js"
></script>
<iframe
    data-capell-consent="youtube"
    data-src="https://www.youtube-nocookie.com/embed/VIDEO"
    title="Product tour"
></iframe>
```

The script keeps its attributes and nonce; `data-type` becomes its `type`. Content added later is activated when it dispatches `capell:content-ready`, and gated content is activated again after a choice. A frame loads only an `http` or `https` address.

## Defaults when no consent manager is installed

The default runtime records nothing and sets no cookie. Configure it in `config/capell.php`:

```php
'privacy' => [
    'first_party_counter' => env('CAPELL_PRIVACY_FIRST_PARTY_COUNTER', true),
    'consent' => [
        'runtime' => env('CAPELL_PRIVACY_CONSENT_RUNTIME', true),
        'defaults' => [
            'functional' => 'undecided',
            'preferences' => 'undecided',
            'analytics' => 'undecided',
            'marketing' => 'undecided',
        ],
        'opt_out_signal_categories' => ['analytics', 'marketing'],
        'purposes' => ['youtube' => 'marketing'],
    ],
],
```

Nobody has been asked, so every optional category starts `undecided` and its gated scripts and frames stay off. Set a category to `granted` only where the site does not need a visitor's consent for it. Each default is `granted`, `denied` or `undecided`; anything else is `undecided`. A Global Privacy Control (`Sec-GPC: 1`, `navigator.globalPrivacyControl`) or Do Not Track (`DNT: 1`) signal refuses the opt-out categories whatever their default. Map each named purpose your package asks about to its category; an unmapped purpose is `null`.

| Config key                                         | Environment variable                 | Default                      |
| -------------------------------------------------- | ------------------------------------ | ---------------------------- |
| `capell.privacy.first_party_counter`               | `CAPELL_PRIVACY_FIRST_PARTY_COUNTER` | `true`                       |
| `capell.privacy.consent.runtime`                   | `CAPELL_PRIVACY_CONSENT_RUNTIME`     | `true`                       |
| `capell.privacy.consent.defaults.functional`       | `CAPELL_PRIVACY_CONSENT_FUNCTIONAL`  | `undecided`                  |
| `capell.privacy.consent.defaults.preferences`      | `CAPELL_PRIVACY_CONSENT_PREFERENCES` | `undecided`                  |
| `capell.privacy.consent.defaults.analytics`        | `CAPELL_PRIVACY_CONSENT_ANALYTICS`   | `undecided`                  |
| `capell.privacy.consent.defaults.marketing`        | `CAPELL_PRIVACY_CONSENT_MARKETING`   | `undecided`                  |
| `capell.privacy.consent.opt_out_signal_categories` | None                                 | `['analytics', 'marketing']` |
| `capell.privacy.consent.purposes`                  | None                                 | `[]`                         |

Setting `runtime` to `false` removes the default runtime from every page; widgets then find no `window.CapellPrivacy` unless a consent manager defines it.

## Capell's page-view counter

The counter records two things: an aggregate page-view count per page in five-minute buckets (each row is stamped with its bucket start, never the moment of a visit), and a daily visitor marker (a truncated HMAC of site, address and user agent that rotates each day). It sets no cookie. The marker is a pseudonymous visitor identifier, so it is recorded only when `analytics` is granted. The aggregate count holds no identifier, so `capell.privacy.first_party_counter` (on by default) lets it count while `analytics` is undecided.

| Analytics decision                              | Page-view count                   | Visitor marker |
| ----------------------------------------------- | --------------------------------- | -------------- |
| Granted                                         | Yes                               | Yes            |
| Undecided                                       | Only with the first-party counter | No             |
| Refused, Global Privacy Control or Do Not Track | No                                | No             |
| Unreadable: no runtime, or the provider fails   | No                                | No             |

The browser and the collection endpoint each apply this table, so a forged beacon still gets no visitor marker without granted analytics. Both treat a Global Privacy Control or Do Not Track signal as a refusal even when a consent manager reports analytics as granted.

## Content Security Policy

The default runtime is a same-origin script without a nonce, because cached HTML cannot carry a per-request one. A policy that allows `'self'` scripts runs it. A nonce-only policy (for example `script-src 'nonce-…' 'strict-dynamic'`) blocks it: `window.CapellPrivacy` is then undefined, gated scripts and frames stay off, and the page-view counter sends nothing because it cannot read consent. Add `'self'`, or a consent manager whose runtime your policy allows.

## Decide on the server

Resolve `Capell\Core\Contracts\Privacy\ConsentDecisionProvider` and call `decide($purposeOrCategory, $request)`. It returns a `ConsentDecision` (`Granted`, `Denied` or `Undecided`); send a signal only for `Granted`.

The server decision reads the request, so use it only in responses that are never cached, such as a tracking endpoint. Public HTML is cached and shared between visitors: never vary page output on a consent decision. Let the browser runtime decide instead.

## Replace the provider

A consent manager binds its own `ConsentDecisionProvider` in its service provider and renders its own `window.CapellPrivacy` runtime at `RenderHookLocation::HeadOpen` with a priority below 5. The default runtime then sees the existing object and leaves it in place. Its provider and runtime should refuse the categories in `capell.privacy.consent.opt_out_signal_categories` under a Global Privacy Control or Do Not Track signal and treat an undecided category as not granted, as Core's do.

The runtime fragment is cached with the page. Keep it free of request state: render the configuration as JSON in a `<script type="application/json">` element and load the code from a same-origin, versioned script URL. The configuration element is data, which a Content Security Policy does not block. Set no consent cookie on a page response. A cookie the consent manager sets from its own endpoint must stay host-only unless the site configures a domain for it. Build that cookie with `Symfony\Component\HttpFoundation\Cookie::create()` and a `null` domain rather than `cookie()`: Laravel's cookie jar substitutes `config('session.domain')` for a `null` domain, which shares the cookie with every subdomain.
