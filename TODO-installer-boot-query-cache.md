# TODO: Installer boot-query cache verification

The installer/provider cache change could not be run locally because Docker
could not allocate this checkout's derived network:
`all predefined address pools have been fully subnetted`.

After safely freeing a Docker network slot, run the focused installer/core
tests, then Pint, static analysis, and the relevant backend suite. Remove this
note when those checks are recorded as passing.
