This scaffold was created without `--local`, so the provider registers only after
Capell records the package as installed. Until then, and whenever the package is
disabled or uninstalled, the theme is not registered and cannot be selected.
Run `capell:install --theme={{ themeKey }}` (above) to install it. Use
`--local` when scaffolding if the theme must register without an installed
package record.
