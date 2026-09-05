import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'

const source = readFileSync(
    new URL('../../resources/js/agent-bridge.js', import.meta.url),
    'utf8',
)
function fixture({
    supported = true,
    legacy = false,
    effect = 'read',
    endpoint = '/agent/v1/pages',
    typed = false,
    includeForm = false,
    formExists = true,
    formAction = '/contact',
    confirmResult = true,
    formPrevented = false,
    confirmationMessage = 'Submit this form?',
    formProperties = {
        email: { type: 'string', maxLength: 200 },
        message: { type: 'string', maxLength: 500 },
    },
} = {}) {
    const registered = []
    const signals = new Map()
    let notifyMutation = () => {}
    const requests = []
    const confirmations = []
    const formEvents = []
    let submissions = 0
    let currentFormAction = formAction
    const controls = Object.entries(formProperties).map(([name, schema]) => ({
        name,
        tagName: 'INPUT',
        type: schema.format === 'password' ? 'password' : 'text',
        value: '',
        checked: false,
        dispatchEvent(event) {
            formEvents.push(`${name}:${event.type}:${event.bubbles}`)
        },
    }))
    const form = {
        tagName: 'FORM',
        getAttribute(name) {
            return name === 'action' ? currentFormAction : null
        },
        elements: {
            namedItem(name) {
                return controls.filter((control) => control.name === name)
            },
        },
        addEventListener(type, listener) {
            if (type === 'submit') this.submitListener = listener
        },
        requestSubmit() {
            submissions += 1
            this.submitListener?.({ defaultPrevented: formPrevented })
        },
    }
    const context = {
        registerTool(tool, options) {
            signals.set(tool.name, options.signal)
            registered.push(tool)
        },
    }
    const island = {
        capellAgentSchema: 1,
        tools: [
            {
                name: 'page.get',
                description: 'Read this page',
                effect,
                binding: typed ? { type: 'inline', target: 'page' } : 'inline',
                inputSchema: {
                    type: 'object',
                    properties: {},
                    additionalProperties: false,
                },
            },
            {
                name: 'site.pages.query',
                description: 'Query pages',
                effect,
                binding: typed
                    ? { type: 'endpoint', target: endpoint }
                    : endpoint,
                inputSchema: {
                    type: 'object',
                    properties: { set: { type: 'string', maxLength: 100 } },
                    additionalProperties: false,
                },
            },
        ],
        messages: { confirmForm: confirmationMessage },
    }
    if (includeForm) {
        island.tools.push({
            name: 'contact.submit',
            description: 'Submit the contact form',
            effect: 'write',
            binding: { type: 'form', target: 'contact-form' },
            inputSchema: {
                type: 'object',
                properties: formProperties,
                additionalProperties: false,
            },
        })
    }
    const nodes = [{ textContent: JSON.stringify(island) }]
    const document = {
        documentElement: {},
        querySelectorAll() {
            return nodes
        },
        querySelector(selector) {
            return {
                textContent: JSON.stringify(
                    selector.includes('tools')
                        ? island
                        : { '@graph': [{ name: 'Example' }] },
                ),
            }
        },
        getElementById(id) {
            return formExists && id === 'contact-form' ? form : null
        },
    }
    const navigator = {}
    if (supported) (legacy ? navigator : document).modelContext = context
    vm.runInNewContext(source, {
        document,
        navigator,
        URL,
        URLSearchParams,
        AbortController,
        MutationObserver: class {
            constructor(callback) {
                notifyMutation = callback
            }
            observe() {}
        },
        Event: class Event {
            constructor(type, options) {
                this.type = type
                this.bubbles = options?.bubbles ?? false
            }
        },
        location: {
            origin: 'https://example.test',
            href: 'https://example.test/current',
        },
        confirm: (message) => {
            confirmations.push(message)
            return confirmResult
        },
        fetch: async (url, options) => {
            requests.push({ url, options })
            return { ok: true, json: async () => ({ data: [] }) }
        },
    })
    return {
        registered,
        signals,
        addManifest(manifest) {
            const node = { textContent: JSON.stringify(manifest) }
            nodes.push(node)
            notifyMutation()
            return () => {
                nodes.splice(nodes.indexOf(node), 1)
                notifyMutation()
            }
        },
        requests,
        confirmations,
        formEvents,
        controls,
        form,
        setFormAction(action) {
            currentFormAction = action
        },
        get submissions() {
            return submissions
        },
    }
}

test('silently does nothing without browser support', () =>
    assert.equal(fixture({ supported: false }).registered.length, 0))
test('supports current and legacy browser API locations', () => {
    assert.equal(fixture().registered.length, 2)
    assert.equal(fixture({ legacy: true }).registered.length, 2)
})
test('answers local tools from inline data without a request', async () => {
    const { registered, requests } = fixture()
    const result = await registered[0].execute({})
    assert.equal(
        JSON.parse(result.content[0].text)['@graph'][0].name,
        'Example',
    )
    assert.equal(requests.length, 0)
})
test('refuses write bindings and foreign endpoints', () => {
    assert.equal(fixture({ effect: 'write' }).registered.length, 0)
    assert.equal(
        fixture({ endpoint: 'https://evil.test/agent/v1/pages' }).registered
            .length,
        1,
    )
})
test('validates remote input and fetches anonymously without following redirects', async () => {
    const { registered, requests } = fixture()
    await assert.rejects(() => registered[1].execute({ extra: 'unexpected' }))
    await assert.rejects(() => registered[1].execute({ set: 42 }))
    await registered[1].execute({ set: 'commerce.product' })
    assert.equal(
        requests[0].url,
        'https://example.test/agent/v1/pages?set=commerce.product',
    )
    assert.equal(requests[0].options.credentials, 'omit')
    assert.equal(requests[0].options.redirect, 'error')
})

test('executes typed bindings and rejects foreign endpoints', async () => {
    const { registered, requests } = fixture({ typed: true })
    assert.equal(registered.length, 2)
    await registered[0].execute({})
    await registered[1].execute({ set: 'example' })
    assert.equal(requests.length, 1)
    assert.equal(
        fixture({ typed: true, endpoint: 'https://evil.test/read' }).registered
            .length,
        1,
    )
})

test('allows validated same-origin typed endpoints outside the core allowlist', async () => {
    const { registered, requests } = fixture({
        typed: true,
        endpoint: '/custom/contact-options',
    })

    await registered[1].execute({ set: 'example' })
    assert.equal(
        requests[0].url,
        'https://example.test/custom/contact-options?set=example',
    )
    assert.equal(
        fixture({ typed: true, endpoint: '//evil.test/custom' }).registered
            .length,
        1,
    )
})

test('registers only an existing same-origin write form', () => {
    const { registered } = fixture({ includeForm: true })

    assert.equal(registered.length, 3)
    assert.equal(registered[2].annotations.readOnlyHint, false)
    assert.equal(
        fixture({ includeForm: true, formAction: 'https://evil.test/contact' })
            .registered.length,
        2,
    )
    assert.equal(
        fixture({ includeForm: true, formAction: 'javascript:alert(1)' })
            .registered.length,
        2,
    )
    assert.equal(
        fixture({ includeForm: true, formExists: false }).registered.length,
        2,
    )
})

test('rejects undeclared and protected form fields', async () => {
    const { registered } = fixture({ includeForm: true })

    await assert.rejects(() => registered[2].execute({ unknown: 'value' }))

    const protectedFixture = fixture({
        includeForm: true,
        formProperties: {
            csrf: { type: 'string' },
            password: { type: 'string', format: 'password' },
            upload: { type: 'string' },
            submit: { type: 'string' },
        },
    })
    protectedFixture.controls[0].type = 'hidden'
    protectedFixture.controls[2].type = 'file'
    protectedFixture.controls[3].type = 'submit'
    for (const field of ['csrf', 'password', 'upload', 'submit']) {
        await assert.rejects(() =>
            protectedFixture.registered[2].execute({ [field]: 'value' }),
        )
    }
})

test('cancellation performs no submission and returns pending', async () => {
    const form = fixture({ includeForm: true, confirmResult: false })
    const result = await form.registered[2].execute({
        email: 'person@example.test',
    })

    assert.deepEqual(JSON.parse(result.content[0].text), {
        status: 'pending',
        cancelled: true,
    })
    assert.equal(form.submissions, 0)
    assert.equal(form.controls[0].value, '')
    assert.equal(form.confirmations.length, 1)
})

test('native confirmation sets allowed fields and requests browser submission', async () => {
    const form = fixture({
        includeForm: true,
        confirmationMessage: 'Submit this form with the following values?',
    })
    const result = await form.registered[2].execute({
        email: 'person@example.test',
        message: 'Please contact me',
    })

    assert.deepEqual(JSON.parse(result.content[0].text), {
        status: 'submitted',
    })
    assert.equal(form.submissions, 1)
    assert.equal(form.controls[0].value, 'person@example.test')
    assert.equal(form.controls[1].value, 'Please contact me')
    assert.match(form.confirmations[0], /following values/i)
    assert.match(form.confirmations[0], /person@example\.test/)
    assert.deepEqual(form.formEvents, [
        'email:input:true',
        'email:change:true',
        'message:input:true',
        'message:change:true',
    ])
})

test('revalidates a form action and validates select choices at execution time', async () => {
    const form = fixture({ includeForm: true })
    form.setFormAction('https://evil.test/contact')
    await assert.rejects(() =>
        form.registered[2].execute({ email: 'person@example.test' }),
    )
    assert.equal(form.submissions, 0)

    const select = fixture({ includeForm: true })
    select.controls[0].tagName = 'SELECT'
    select.controls[0].type = 'select-one'
    select.controls[0].options = [{ value: 'one', selected: false }]
    await assert.rejects(() => select.registered[2].execute({ email: 'two' }))
    await select.registered[2].execute({ email: 'one' })
    assert.equal(select.controls[0].value, 'one')
})

test('does not assign controls in a disabled fieldset', async () => {
    const form = fixture({ includeForm: true })
    form.controls[0].matches = (selector) => selector === ':disabled'
    await assert.rejects(() =>
        form.registered[2].execute({ email: 'person@example.test' }),
    )
    assert.equal(form.submissions, 0)
})

test('reports pending when a framework prevents the submit event', async () => {
    const form = fixture({ includeForm: true, formPrevented: true })
    const result = await form.registered[2].execute({
        email: 'person@example.test',
    })

    assert.deepEqual(JSON.parse(result.content[0].text), {
        status: 'pending',
    })
    assert.equal(form.submissions, 1)
})

test('does not expose an invented model-callable interaction API', () => {
    assert.equal(source.includes('requestUserInteraction'), false)
    assert.equal(source.includes('autoConfirm'), false)
})

test('registers a later form island and retires its tool when Livewire removes it', async () => {
    const page = fixture()
    const remove = page.addManifest({
        capellAgentSchema: 1,
        messages: { confirmForm: 'Submit this form?' },
        tools: [
            {
                name: 'contact.later.submit',
                description: 'Submit the visible contact form',
                effect: 'write',
                binding: { type: 'form', target: 'contact-form' },
                inputSchema: {
                    type: 'object',
                    properties: { email: { type: 'string' } },
                    additionalProperties: false,
                },
            },
        ],
    })
    const tool = page.registered.find(
        (tool) => tool.name === 'contact.later.submit',
    )
    assert.ok(tool)
    remove()
    assert.equal(page.signals.get(tool.name).aborted, true)
    await assert.rejects(
        tool.execute({ email: 'test@example.test' }),
        /no longer available/,
    )
    assert.equal(page.submissions, 0)
})

test('does not let another manifest replace an already registered tool', () => {
    const page = fixture()
    page.addManifest({
        capellAgentSchema: 1,
        tools: [
            {
                name: 'page.get',
                description: 'Replacement',
                effect: 'read',
                binding: 'inline',
                inputSchema: {
                    type: 'object',
                    properties: {},
                    additionalProperties: false,
                },
            },
        ],
    })
    assert.equal(
        page.registered.filter((tool) => tool.name === 'page.get').length,
        1,
    )
})

test('selects one member of a radio group and rejects unknown choices', async () => {
    const form = fixture({
        includeForm: true,
        formProperties: { contact: { type: 'string' } },
    })
    Object.assign(form.controls[0], { type: 'radio', value: 'email' })
    form.controls.push({ ...form.controls[0], value: 'phone' })
    await form.registered[2].execute({ contact: 'phone' })
    assert.equal(form.controls[0].checked, false)
    assert.equal(form.controls[1].checked, true)
    assert.equal(form.submissions, 1)
    await assert.rejects(form.registered[2].execute({ contact: 'unknown' }))
    assert.equal(form.submissions, 1)
})
