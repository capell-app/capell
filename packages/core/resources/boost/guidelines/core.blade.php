# Capell Core Capell is a Laravel/Filament CMS. Keep upfront context small: use
the `capell` skill for Capell-specific details and `search-docs` for Laravel
ecosystem details when needed. - Keep business logic in Actions and structured
boundaries in Data objects. - Public frontend output must not expose
admin/editor metadata, selectors, model IDs, field paths, permissions, or signed
URLs. - Public Blade must receive hydrated render data; do not query or
lazy-load in views. - Use Capell registries and extension points instead of
editing core for package behaviour.
