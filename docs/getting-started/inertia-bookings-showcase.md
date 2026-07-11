# Inertia Bookings Showcase

![Capell Inertia Bookings Showcase screenshot](../images/capell-readme-banner.jpg)

`capell-app/theme-inertia-bookings` is the first full Inertia theme slice. It shows how a premium booking-business theme can render Capell marketing pages through Inertia and link into the real Bookings appointment request flow.

The theme is for service businesses, clinics, consultants, classes, and appointment-led sites. It does not add theme-owned booking models, migrations, or admin resources.

## Package Set

| Package                                                                | Role                                                                                              |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| `capell-app/bookings`                                                  | Booking services, staff, locations, availability, exceptions, and appointment request submission. |
| `capell-app/inertia`                                                   | Shared Inertia renderer and package route bridge.                                                 |
| `capell-app/inertia-vue-adapter` or `capell-app/inertia-react-adapter` | Client adapter selected by `CAPELL_INERTIA_ADAPTER`.                                              |
| `capell-app/theme-inertia-bookings`                                    | Theme manifest, runtime definition, demo install, and Bookings renderer binding.                  |
| `capell-app/theme-inertia-bookings-vue`                                | Vue components for `Capell/Page` and `Capell/Bookings/Request`.                                   |
| `capell-app/theme-inertia-bookings-react`                              | React components for the same server component contract.                                          |

The extension documentation entry point lives in [Packages and extensions](../packages/catalog.md).

## Install And Enable

Install the booking theme with the runtime, selected adapter, matching theme component pack, and Bookings package:

```bash
composer require capell-app/bookings capell-app/inertia capell-app/inertia-vue-adapter capell-app/theme-inertia-bookings capell-app/theme-inertia-bookings-vue
php artisan capell:package-cache:clear
php artisan capell:package-cache
php artisan capell:extension-install capell-app/bookings --dry-run
php artisan capell:extension-install capell-app/bookings
php artisan capell:extension-install capell-app/inertia
php artisan capell:extension-install capell-app/inertia-vue-adapter
php artisan capell:extension-install capell-app/theme-inertia-bookings
php artisan capell:extension-install capell-app/theme-inertia-bookings-vue
```

For React, make these substitutions in the Vue commands, then set `CAPELL_INERTIA_ADAPTER=react` in `.env`:

| Vue package                             | React package                             |
| --------------------------------------- | ----------------------------------------- |
| `capell-app/inertia-vue-adapter`        | `capell-app/inertia-react-adapter`        |
| `capell-app/theme-inertia-bookings-vue` | `capell-app/theme-inertia-bookings-react` |

After the packages are enabled, activate the theme key `inertia-bookings` through the Theme Library or the install flow. Installing the packages makes the runtime available; selecting the theme is what makes public page requests resolve through `FrontendRuntime::Inertia`.

## Theme Runtime

The theme definition uses `FrontendRuntime::Inertia`. When the theme is active, Capell resolves the page through the normal frontend route, builds the public page payload through the API package Actions, and renders `Capell/Page` through the selected adapter.

Marketing page content stays portable:

- CMS fields store semantic copy and public data;
- Layout Builder widgets carry public widget data;
- theme and adapter packages own the presentation components;
- authoring/admin concerns stay out of public props and rendered output.

## Booking Request Flow

The public `/bookings` flow uses the existing Bookings package, not mock slots:

| Surface              | Implementation                           |
| -------------------- | ---------------------------------------- |
| Booking options      | `BuildPublicBookingRequestOptionsAction` |
| Available slots      | `BuildAvailableBookingSlotsAction`       |
| Public request props | `BuildPublicBookingRequestPropsAction`   |
| Renderer contract    | `PublicBookingRequestRenderer`           |
| Inertia renderer     | `InertiaPublicBookingRequestRenderer`    |
| Submission           | `CreateAppointmentRequestAction`         |

The theme binds `PublicBookingRequestRenderer` to the Inertia renderer. The default Bookings package renderer remains Blade, so existing installs keep their current public form unless the Inertia theme is active.

## Lazy Slot Loading

`BuildPublicBookingRequestPropsAction` can return `slots` as an Inertia optional prop. The request page can load the service/staff/location form first, then request only `slots` through an Inertia partial reload when the visitor changes filters or timezone.

Slot generation respects:

- active services;
- staff and location filters;
- availability windows;
- availability exceptions;
- requested timezone;
- configured public slot interval;
- service duration and capacity rules.

Use Capell lazy widget and fragment endpoints for visitor-triggered panels on marketing pages. Use Inertia optional props for the booking request page's expensive slot data.

## Response Rules

Booking request pages handle visitor intent and should not be cached as static marketing HTML. Keep these response rules:

- `Cache-Control` includes `private` and `no-store`;
- `X-Robots-Tag: noindex, nofollow`;
- validation redirects must preserve normal Laravel form behavior;
- successful submissions must still go through `CreateAppointmentRequestAction`.

Payments, customer portal, cancellation, and rescheduling are outside this first Inertia slice.

## Demo Install

The showcase demo installer seeds portable content and real booking fixtures:

- CMS pages and Layout Builder widgets;
- booking services;
- staff members;
- locations;
- recurring availability windows;
- one blocked exception.

The demo content should not store theme utility classes or designed markup in editable database fields. Presentation belongs in the theme and adapter components.
