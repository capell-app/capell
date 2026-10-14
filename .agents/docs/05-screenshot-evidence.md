## Screenshot Evidence

- Documentation, release, and promotional screenshots are accepted only when
  produced by the shared `capell-screenshot-runner` through this repository's
  `@capell-app/screenshot-tools` entry point and backed by its report/receipt
  provenance. Manifests must retain `generatedFor` set to
  `shared-capell-screenshot-runner` and `provenancePolicy` set to `runner-only-v1`.
- Direct captures from `capell-app`, `capell.test`, or an ad-hoc browser session are
  diagnostics only and must remain ignored. They cannot be promoted as accepted
  artifacts, and capturing Core evidence does not authorize changes in the
  consuming App.
- Validate manifests with `npm run screenshots:check`. Prepare and capture through
  `bash scripts/local-core-screenshots.sh --package <name>` (omit the filter for all
  Core manifests). Inside the Codex sandbox pass `--skip-install` and never run
  `npm ci`/`npm install` yourself: the sandbox cannot chmod the runner-linked bin,
  and disabling bin links to get past that empties `node_modules/.bin`. Report
  missing dependencies instead. Validate documentation coverage and provenance with
  `npm run docs:screenshots:check` and `npm run docs:screenshot-receipts`.
