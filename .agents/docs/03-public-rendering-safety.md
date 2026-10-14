## Public Rendering Safety

Anonymous HTML, cached responses, crawler output, and static exports must contain no
authoring HTML or JavaScript, edit markers, model IDs, field paths, permissions,
package names, component internals, or signed editor URLs. Frontend authoring is a
post-load, authenticated-admin concern. Public-output, render-hook, cache, theme,
fragment, or export changes require absence assertions as well as expected-content
tests.
