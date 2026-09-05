const { expect } = require('@playwright/test')

const ALLOWED_CAPELL_ATTRIBUTES = new Set([
    'data-capell-agent-schema',
    'data-capell-agent-tools',
    'data-capell-cookie',
    'data-capell-interaction',
    'data-capell-origin-cookie',
    'data-capell-page-language',
    'data-capell-stylesheet-fallback',
    'data-capell-stylesheet-fallback-active',
    'data-capell-stylesheet-recovery',
    'data-capell-stylesheet-recovery-runtime',
])

const ALLOWED_CAPELL_ATTRIBUTE_PREFIXES = [
    'data-capell-insights-',
    'data-capell-interaction-',
    'data-capell-language-',
    'data-capell-theme-',
    'data-capell-widget-',
]

const BLOCKED_PATTERNS = [
    {
        label: 'authoring data attribute',
        pattern:
            /\bdata-(?:capell-authoring|capell-editable|capell-editor(?:-url)?|field-path|model-id|permission|capell-package)\b/i,
    },
    {
        label: 'authoring class or id',
        pattern:
            /(?:class|id)\s*=\s*["'][^"']*\bcapell-(?:authoring|editor)\b/i,
    },
    {
        label: 'authoring model metadata',
        pattern:
            /(?:["']|&quot;)?\b(?:fieldPath|field[_-]?path|modelId|model[_-]?id|pageId|page[_-]?id|editorUrl|editor_url|signedEditorUrl|signed_editor_url|signedAdminUrl|signed_admin_url|editable_regions)\b(?:["']|&quot;)?\s*(?:=|:)/i,
    },
    {
        label: 'admin or authoring runtime URL',
        pattern:
            /(?<![A-Za-z0-9_-])\/(?:admin|authoring\/regions|filament|filament-peek|livewire)(?:[/?#)"'\s]|$)/i,
    },
    {
        label: 'bearer credential',
        pattern:
            /\b(?:Authorization\s*[:=]\s*)?Bearer\s+[A-Za-z0-9._~+/=-]{8,}\b/i,
    },
    {
        label: 'credential assignment',
        pattern:
            /\b(?:secret|token|password|passwd|pwd|credential|private_key|api_key|access_key|client_secret|webhook_secret|signing_secret)\s*[:=]\s*[^"'\s,;}{]+/i,
    },
    {
        label: 'signed or credential query parameter',
        pattern: /[?&](?:token|access_token|refresh_token)=[^&\s<>"']+/i,
    },
]

const CACHE_BLOCKED_PATTERNS = [
    {
        label: 'baked CSRF input',
        pattern:
            /<input\b(?=[^>]*\bname\s*=\s*["']_token["'])(?=[^>]*\bvalue\s*=\s*["'][^"']+)[^>]*>/i,
    },
    {
        label: 'baked CSRF meta tag',
        pattern:
            /<meta\b(?=[^>]*\bname\s*=\s*["']csrf-token["'])(?=[^>]*\bcontent\s*=\s*["'][^"']+)[^>]*>/i,
    },
    {
        label: 'Livewire session state',
        pattern: /(?:wire:snapshot|livewireScriptConfig\s*=|data-csrf\s*=)/i,
    },
]

function inspectPublicHtml(
    html,
    forbiddenValues = [],
    { cacheable = true } = {},
) {
    const issues = []
    const blockedPatterns = cacheable
        ? [...BLOCKED_PATTERNS, ...CACHE_BLOCKED_PATTERNS]
        : BLOCKED_PATTERNS

    for (const { label, pattern } of blockedPatterns) {
        if (pattern.test(html)) {
            issues.push(label)
        }
    }

    for (const match of html.matchAll(
        /\b(data-capell-[a-z0-9-]+)(?:\s*=|\s|>)/gi,
    )) {
        const attribute = match[1].toLowerCase()
        const allowed =
            ALLOWED_CAPELL_ATTRIBUTES.has(attribute) ||
            ALLOWED_CAPELL_ATTRIBUTE_PREFIXES.some((prefix) =>
                attribute.startsWith(prefix),
            )

        if (!allowed) {
            issues.push(`unknown public Capell attribute: ${attribute}`)
        }
    }

    const foldedHtml = html.toLocaleLowerCase('en-GB')

    for (const value of forbiddenValues) {
        const candidate = String(value).trim()

        if (
            candidate !== '' &&
            foldedHtml.includes(candidate.toLocaleLowerCase('en-GB'))
        ) {
            issues.push('authenticated or authoring-only value')
        }
    }

    return [...new Set(issues)]
}

async function expectSafePublicHtml(page, forbiddenValues, options = {}) {
    const html = await page.content()

    expect(inspectPublicHtml(html, forbiddenValues, options)).toEqual([])
}

async function expectPublicPage({
    page,
    response,
    title,
    absentTitles = [],
    cache,
    forbiddenValues,
}) {
    expect(response, 'public navigation must return a response').not.toBeNull()
    expect(response.status(), 'public navigation status').toBe(200)
    expect(response.headers()['x-frontend-cache'], 'public cache state').toBe(
        cache,
    )
    await expect(
        page.getByRole('heading', { name: title, exact: true }).first(),
    ).toBeVisible()

    for (const absentTitle of absentTitles) {
        await expect(page.getByText(absentTitle, { exact: true })).toHaveCount(
            0,
        )
    }

    await expectSafePublicHtml(page, forbiddenValues)
}

async function expectDraftIsPrivate({
    page,
    response,
    title,
    forbiddenValues,
}) {
    expect(response, 'draft navigation must return a response').not.toBeNull()
    expect(response.status(), 'draft public status').toBe(404)
    await expect(page.getByText(title, { exact: true })).toHaveCount(0)
    await expectSafePublicHtml(page, forbiddenValues)
}

module.exports = {
    expectDraftIsPrivate,
    expectPublicPage,
    expectSafePublicHtml,
    inspectPublicHtml,
}
