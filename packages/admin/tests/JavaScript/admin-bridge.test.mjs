import assert from 'node:assert/strict'
import test from 'node:test'
import bridge from '../../resources/js/agent/admin-bridge.js'

function fixture() {
    const tools = []
    const calls = []
    const elements = []
    globalThis.location = { origin: 'https://admin.test' }
    globalThis.document = {
        modelContext: {
            registerTool(tool) {
                tools.push(tool)
            },
        },
        body: { append() {} },
        createElement(tag) {
            const listeners = {}
            const element = {
                tag,
                listeners,
                style: {},
                append() {},
                remove() {},
                showModal() {},
                focus() {},
                addEventListener(name, callback) {
                    listeners[name] = callback
                },
            }
            elements.push(element)
            return element
        },
    }
    globalThis.fetch = async (url, options) => {
        calls.push({ url, options })
        if (!options.method)
            return {
                ok: true,
                json: async () => ({
                    capellAgentSchema: 1,
                    tools: [
                        {
                            name: 'admin.page.update',
                            effect: 'write',
                            description: 'Update',
                            inputSchema: {},
                        },
                    ],
                }),
            }
        const payload = JSON.parse(options.body)
        return {
            ok: true,
            json: async () =>
                payload.confirmation_token
                    ? {
                          ok: true,
                          mode: 'executed',
                          confirmationToken: 'must-not-leak',
                      }
                    : {
                          ok: true,
                          mode: 'confirmation_required',
                          confirmationToken: 'secret',
                          data: { title: '<script>evil()</script>' },
                      },
        }
    }
    const component = bridge({
        definitionsUrl: '/tools',
        invokeUrl: '/invoke',
        csrf: 'csrf',
        title: 'Review change',
        approve: 'Apply',
        cancel: 'Cancel',
        error: 'Failed',
        busy: 'Busy',
    })
    return { component, tools, calls, elements }
}
const tick = () => new Promise((resolve) => setImmediate(resolve))

test('write requires a trusted confirmation, and tokens never reach the model', async () => {
    const { component, tools, calls, elements } = fixture()
    await component.init()
    const invocation = tools[0].execute({ title: 'Changed' })
    await tick()
    assert.equal(calls.length, 2)
    const buttons = elements.filter((element) => element.tag === 'button')
    buttons[1].listeners.click({ isTrusted: false })
    await tick()
    assert.equal(calls.length, 2)
    assert.match(
        elements.find((element) => element.tag === 'pre').textContent,
        /<script>/,
    )
    buttons[1].listeners.click({ isTrusted: true })
    const result = await invocation
    assert.equal(calls.length, 3)
    assert.deepEqual(JSON.parse(calls[2].options.body), {
        tool: 'admin.page.update',
        confirmation_token: 'secret',
    })
    assert.equal(calls[2].options.headers['X-CSRF-TOKEN'], 'csrf')
    assert.doesNotMatch(
        JSON.stringify(result),
        /secret|confirmationToken|must-not-leak/,
    )
    component.destroy()
})

test('cancelling a preview performs no write', async () => {
    const { component, tools, calls, elements } = fixture()
    await component.init()
    const invocation = tools[0].execute({})
    await tick()
    elements.find((element) => element.tag === 'button').listeners.click()
    const result = await invocation
    assert.equal(calls.length, 2)
    assert.equal(JSON.parse(result.content[0].text).mode, 'cancelled')
    component.destroy()
})

test('leaving the component cancels the open confirmation', async () => {
    const { component, tools, calls } = fixture()
    await component.init()
    const invocation = tools[0].execute({})
    await tick()
    await assert.rejects(() => tools[0].execute({}), /Busy/)
    component.destroy()
    assert.equal(
        JSON.parse((await invocation).content[0].text).mode,
        'cancelled',
    )
    assert.equal(calls.length, 2)
})
