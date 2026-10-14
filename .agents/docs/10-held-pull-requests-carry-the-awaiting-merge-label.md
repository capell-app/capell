## Held pull requests carry the `awaiting-merge` label

A reviewed, ready pull request whose merge must wait (most often until a running
release has deployed) gets the `awaiting-merge` label, which exists in the App,
Core and Packages repositories. Anyone can then list what is queued with
`gh pr list --label awaiting-merge`. Remove the label in the same step as the
merge. Never leave a held PR unlabelled: an unlabelled open PR reads as still in
progress and gets forgotten.

While an App release is running, hold every App merge this way: release
preflight requires the App worktree to equal `origin/main`, so any App merge,
even documentation, invalidates the prepared release. Core and Packages merges
are safe mid-release because the release pins their refs.
