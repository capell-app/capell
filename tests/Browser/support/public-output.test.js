const test = require('node:test')
const assert = require('node:assert/strict')

const { inspectPublicHtml } = require('./public-output')

test('accepts documented anonymous runtime attributes', () => {
    const html = `
        <main data-capell-theme-preset="default">
            <div data-capell-widget-key="hero">Expected public copy</div>
        </main>
    `

    assert.deepEqual(inspectPublicHtml(html), [])
})

test('rejects authoring metadata, admin URLs, and authenticated values', () => {
    const html = `
        <main data-capell-model-id="42" data-capell-internal-state="draft">
            <a href="/admin/pages/42/edit?signature=private">Edit</a>
            CAP-0266 CI Editor
        </main>
    `

    assert.deepEqual(
        inspectPublicHtml(html, ['CAP-0266 CI Editor']).sort(),
        [
            'admin or authoring runtime URL',
            'authenticated or authoring-only value',
            'authoring model metadata',
            'unknown public Capell attribute: data-capell-internal-state',
            'unknown public Capell attribute: data-capell-model-id',
        ].sort(),
    )
})

test('rejects session-bound CSRF and Livewire state from cacheable HTML', () => {
    const html = `
        <meta name="csrf-token" content="session-bound">
        <div wire:snapshot="{&quot;memo&quot;:{}}"></div>
    `

    assert.deepEqual(inspectPublicHtml(html).sort(), [
        'Livewire session state',
        'baked CSRF meta tag',
    ])
})

test('rejects quoted JSON authoring metadata and admin signed URLs', () => {
    const html = `<script>window.data = {&quot;field_path&quot;:&quot;secret&quot;,&quot;model_id&quot;:42,&quot;signed_url&quot;:&quot;/admin/pages/42?signature=secret&quot;}</script>`

    assert.deepEqual(
        inspectPublicHtml(html).sort(),
        ['admin or authoring runtime URL', 'authoring model metadata'].sort(),
    )
})

test('permits generic public CDN signed URLs', () => {
    const html =
        '<script>window.asset = { signedUrl: "https://cdn.example.test/media/image.jpg?signature=public-cdn" }</script>'

    assert.deepEqual(inspectPublicHtml(html), [])
})

test('keeps authoring checks but permits session state in private previews', () => {
    const html = `
        <meta name="csrf-token" content="session-bound">
        <div wire:snapshot="{}" data-capell-editor-state="draft">
            CAP-0266 CI Editor
        </div>
    `

    assert.deepEqual(
        inspectPublicHtml(html, ['CAP-0266 CI Editor'], {
            cacheable: false,
        }).sort(),
        [
            'authenticated or authoring-only value',
            'authoring data attribute',
            'unknown public Capell attribute: data-capell-editor-state',
        ].sort(),
    )
})
