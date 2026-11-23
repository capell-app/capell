import assert from 'node:assert/strict'
import test from 'node:test'
import { readFile } from 'node:fs/promises'

const view = await readFile(
    new URL('../../resources/views/components/language-suggestion-banner.blade.php', import.meta.url),
    'utf8',
)
const body = view.match(/capell-same-origin-url:start\s*([\s\S]*?)\s*\/\/ capell-same-origin-url:end/)?.[1]

assert.ok(body, 'the same-origin guard must stay delimited in the banner script')

const sameOriginUrl = new Function(
    'window',
    'URL',
    `${body}\nreturn sameOriginUrl`,
)({ location: new URL('https://example.com/fr/page') }, URL)

test('accepts same-origin paths and absolute urls', () => {
    assert.equal(sameOriginUrl('/en/page'), 'https://example.com/en/page')
    assert.equal(sameOriginUrl('https://example.com/en'), 'https://example.com/en')
})

test('refuses script, data, foreign-host, scheme-relative and other-scheme urls', () => {
    for (const value of [
        'javascript:alert(1)',
        ' JaVaScRiPt:alert(1)',
        'data:text/html,x',
        'https://evil.example/',
        '//evil.example/',
        'http://example.com/en',
        'https://example.com.evil.example/',
        'https://[invalid',
    ]) {
        assert.equal(sameOriginUrl(value), null, value)
    }
})
