# Custom page-field screenshot fixture

The Case study field uses the real page schema extender, normal PageResource editor and persisted page metadata. The public example uses the normal anonymous page route and a hydrated public render-data contribution.

The additional sidebar note comes from the exact pinned Extension Cookbook source in `../extension-cookbook/`, loaded as a fixture. This proves that the real contribution composes with the app field; it does not claim that the companion package was installed.

The four screenshot scenarios have independent records to avoid ordering or concurrency dependencies. The reopened editor changes its client through the real Save control and follows the existing record breadcrumb to reopen the persisted record. The public example is independently seeded as Field Notes Studio. The same-record save/reopen/logout/public behaviour is verified separately by `tests/Feature/Documentation/CustomPageFieldsWorkflowTest.php`.

`FocusCustomPageFieldsScreenshot` omits unrelated relation-manager tables only for the named custom-field screenshot records, through `PageSchemaExtender::extendRelationManagers()`. The original owned-runtime capture exposed Alpine column-manager errors in ActivityHistoryRelationManager after a Livewire save. Those failed HTML/JSON/PNG/trace artefacts are retained in the task evidence as a baseline limitation; production table code is unchanged and runner diagnostics remain strict. This fixture focuses the evidence on the page field and real extension contribution.
