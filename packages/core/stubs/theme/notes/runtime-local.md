This scaffold was created with `--local`, so the provider registers its runtime
unconditionally on every boot. The theme is available as soon as Composer
autoloads the package, including during the process that runs
`capell:install`, and in tests. The database `themes` record and the selected
layout still decide whether the public site uses it.
