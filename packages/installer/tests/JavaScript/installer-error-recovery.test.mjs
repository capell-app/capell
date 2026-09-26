import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import vm from 'node:vm'

const scriptsRoot = resolve(
    dirname(fileURLToPath(import.meta.url)),
    '../../resources/js/install',
)

function loadInstallerScript(filename, globals) {
    const context = {
        console,
        ...globals,
    }
    context.globalThis = context
    context.window = context

    vm.createContext(context)
    vm.runInContext(
        readFileSync(resolve(scriptsRoot, filename), 'utf8'),
        context,
        { filename },
    )

    return context
}

function createClassList() {
    const values = new Set()

    return {
        add: (...names) => names.forEach((name) => values.add(name)),
        contains: (name) => values.has(name),
        remove: (...names) => names.forEach((name) => values.delete(name)),
        toggle: (name, enabled) => {
            if (enabled) values.add(name)
            else values.delete(name)
        },
    }
}

test('an empty csrf refresh restores the installer form', async () => {
    const events = []
    const attributes = new Map()
    const submitButton = {
        classList: createClassList(),
        disabled: false,
        setAttribute: (name, value) => attributes.set(name, value),
        removeAttribute: (name) => attributes.delete(name),
    }
    const form = { action: '/install' }
    const context = loadInstallerScript('runner.js', {
        CapellInstaller: {
            support: { responseLooksLikeServerTimeout: () => false },
        },
        document: {
            getElementById: (id) =>
                id === 'submit-button' ? submitButton : null,
        },
        fetch: async () => new Response('{}', { status: 419 }),
        FormData: class {
            set() {}
        },
        Response,
    })
    const runner = context.CapellInstaller.createInstallRunner({
        form,
        wizard: {
            currentStep: () => 'options',
            setFlowStep: (step) => events.push(['step', step]),
            showGlobalError: (message) => events.push(['error', message]),
        },
        packages: { updateSubmitButtonLabel: () => {} },
        progress: { showFormView: () => events.push(['view', 'form']) },
        csrf: {
            token: () => 'expired-token',
            setToken: () => {},
            refresh: async () => '',
        },
        messages: { sessionExpired: 'Session expired' },
    })

    runner.setSubmitting(true)
    await runner.submitInstallForm(false)

    assert.equal(submitButton.disabled, false)
    assert.deepEqual(events, [
        ['view', 'form'],
        ['step', 'options'],
        ['error', 'Session expired'],
    ])
})

test('field errors reveal matched steps and present unmatched messages', () => {
    const inputAttributes = new Map()
    const fieldError = { textContent: '' }
    const siteSection = {
        dataset: { installerStep: 'site' },
        classList: createClassList(),
        hidden: true,
        querySelectorAll: () => [],
    }
    const field = {
        classList: createClassList(),
        closest: (selector) =>
            selector === '[data-installer-step]' ? siteSection : null,
        querySelector: (selector) => {
            if (selector === 'input, select, textarea') return input
            if (selector === '.field-error') return fieldError

            return null
        },
    }
    const input = {
        name: 'site_name',
        disabled: false,
        type: 'text',
        willValidate: true,
        closest: (selector) => {
            if (selector === '.field, [data-field]') return field

            return null
        },
        setAttribute: (name, value) => inputAttributes.set(name, value),
        removeAttribute: (name) => inputAttributes.delete(name),
    }
    const readinessSection = {
        dataset: { installerStep: 'readiness' },
        classList: createClassList(),
        hidden: false,
        querySelectorAll: () => [],
    }
    const sections = [readinessSection, siteSection]
    const errorsBox = { hidden: true }
    const errorsList = {
        children: [],
        appendChild(node) {
            this.children.push(node)
        },
    }
    Object.defineProperty(errorsList, 'innerHTML', {
        get() {
            return ''
        },
        set() {
            this.children = []
        },
    })
    const submitButton = { hidden: true }
    const form = {
        elements: [input],
        querySelector(selector) {
            return selector === '[data-field="site_name"]' ? field : null
        },
        querySelectorAll(selector) {
            if (selector === '.field.has-error') {
                return field.classList.contains('has-error') ? [field] : []
            }
            if (selector === '[aria-invalid]') {
                return inputAttributes.has('aria-invalid') ? [input] : []
            }

            return []
        },
    }
    const document = {
        createElement: () => ({ textContent: '' }),
        getElementById(id) {
            if (id === 'errors') return errorsBox
            if (id === 'errors-list') return errorsList
            if (id === 'submit-button') return submitButton

            return null
        },
        querySelector: () => null,
        querySelectorAll(selector) {
            if (selector === '[data-installer-step]') return sections

            return []
        },
    }
    const context = loadInstallerScript('wizard.js', {
        document,
        matchMedia: () => ({ matches: false }),
        scrollTo: () => {},
    })
    const wizard = context.CapellInstaller.createWizard({ form })

    wizard.showFieldErrors({
        site_name: ['The site name is invalid.'],
        cache_store: ['The configured cache store is unavailable.'],
    })

    assert.equal(wizard.currentStep(), 'site')
    assert.equal(siteSection.hidden, false)
    assert.equal(inputAttributes.get('aria-invalid'), 'true')
    assert.equal(errorsBox.hidden, false)
    assert.deepEqual(
        errorsList.children.map((item) => item.textContent),
        ['The configured cache store is unavailable.'],
    )
})
