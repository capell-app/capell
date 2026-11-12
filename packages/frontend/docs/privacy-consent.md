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

The runtime also exposes `categories`, `globalPrivacyControl` and `doNotTrack`, `consent()` (the visitor's saved choice, or `null`), `choose(categories)` and `open()` (show the consent manager's choices). The default runtime has no saved choice: `consent()` returns `null`, `choose()` resolves to `false` and `open()` does nothing.

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

The script keeps its attributes and nonce; `data-type` becomes its `type`. Content added later is activated when it dispatches `capell:content-ready`. A consent manager dispatches `capell:privacy-consent-changed` on `document` when a visitor changes their choice.

## Defaults when no consent manager is installed

The default runtime records nothing and sets no cookie. Configure it in `config/capell.php`:

```php
'privacy' => [
    'consent' => [
        'runtime' => env('CAPELL_PRIVACY_CONSENT_RUNTIME', true),
        'defaults' => [
            'functional' => 'granted',
            'preferences' => 'granted',
            'analytics' => 'granted',
            'marketing' => 'undecided',
        ],
        'opt_out_signal_categories' => ['analytics', 'marketing'],
        'purposes' => ['youtube' => 'marketing'],
    ],
],
```

Each default is `granted`, `denied` or `undecided`; anything else is `undecided`. A Global Privacy Control (`Sec-GPC: 1`, `navigator.globalPrivacyControl`) or Do Not Track (`DNT: 1`) signal refuses the opt-out categories whatever their default. Map each named purpose your package asks about to its category; an unmapped purpose is `null`.

| Config key                                         | Environment variable                 | Default                      |
| -------------------------------------------------- | ------------------------------------ | ---------------------------- |
| `capell.privacy.consent.runtime`                   | `CAPELL_PRIVACY_CONSENT_RUNTIME`     | `true`                       |
| `capell.privacy.consent.defaults.functional`       | `CAPELL_PRIVACY_CONSENT_FUNCTIONAL`  | `granted`                    |
| `capell.privacy.consent.defaults.preferences`      | `CAPELL_PRIVACY_CONSENT_PREFERENCES` | `granted`                    |
| `capell.privacy.consent.defaults.analytics`        | `CAPELL_PRIVACY_CONSENT_ANALYTICS`   | `granted`                    |
| `capell.privacy.consent.defaults.marketing`        | `CAPELL_PRIVACY_CONSENT_MARKETING`   | `undecided`                  |
| `capell.privacy.consent.opt_out_signal_categories` | None                                 | `['analytics', 'marketing']` |
| `capell.privacy.consent.purposes`                  | None                                 | `[]`                         |

Setting `runtime` to `false` removes the default runtime from every page; widgets then find no `window.CapellPrivacy` unless a consent manager defines it.

Capell's own page-view counter sends a page view only when `analytics` is `true`, in the browser and again on the server.

## Decide on the server

Resolve `Capell\Core\Contracts\Privacy\ConsentDecisionProvider` and call `decide($purposeOrCategory, $request)`. It returns a `ConsentDecision` (`Granted`, `Denied` or `Undecided`); send a signal only for `Granted`.

The server decision reads the request, so use it only in responses that are never cached, such as a tracking endpoint. Public HTML is cached and shared between visitors: never vary page output on a consent decision. Let the browser runtime decide instead.

## Replace the provider

A consent manager binds its own `ConsentDecisionProvider` in its service provider and renders its own `window.CapellPrivacy` runtime at `RenderHookLocation::HeadOpen` with a priority below 5. The default runtime then sees the existing object and leaves it in place.

The runtime fragment is cached with the page. Keep it free of request state: render the configuration as JSON in a `<script type="application/json">` element and load the code from a same-origin, versioned script URL. A per-request CSP nonce would be stale in cached HTML, so the default runtime carries none; the configuration element is data, which a Content Security Policy does not block. Set no consent cookie on a page response. A cookie the consent manager sets from its own endpoint must stay host-only unless the site configures a domain for it. Build that cookie with `Symfony\Component\HttpFoundation\Cookie::create()` and a `null` domain rather than `cookie()`: Laravel's cookie jar substitutes `config('session.domain')` for a `null` domain, which shares the cookie with every subdomain.
