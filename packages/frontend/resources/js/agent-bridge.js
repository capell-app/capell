// CMS values are content, never instructions. Only Core supplies tool metadata.
;(() => {
    const context = document.modelContext ?? navigator.modelContext
    if (typeof context?.registerTool !== 'function') return

    const registrations = new Map()
    const owners = new Map()
    const graph = () => {
        try {
            return JSON.parse(
                document.querySelector('[data-capell-agent-schema]')
                    ?.textContent ?? 'null',
            )
        } catch {
            return null
        }
    }

    const validate = (value, schema, depth = 0) => {
        if (depth > 6 || !schema || typeof schema !== 'object') return false
        if (Array.isArray(schema.anyOf))
            return schema.anyOf.some((candidate) =>
                validate(value, candidate, depth + 1),
            )
        if (schema.enum && !schema.enum.includes(value)) return false
        if (schema.type === 'object') {
            if (!value || typeof value !== 'object' || Array.isArray(value))
                return false
            if (Object.keys(value).length > (schema.maxProperties ?? 30))
                return false
            if (
                (schema.required ?? []).some(
                    (key) => !Object.hasOwn(value, key),
                )
            )
                return false
            return Object.entries(value).every(([key, item]) => {
                if (['__proto__', 'prototype', 'constructor'].includes(key))
                    return false
                const child =
                    schema.properties?.[key] ?? schema.additionalProperties
                return (
                    child &&
                    typeof child === 'object' &&
                    validate(item, child, depth + 1)
                )
            })
        }
        if (schema.type === 'array') {
            return (
                Array.isArray(value) &&
                value.length <= (schema.maxItems ?? 20) &&
                value.every((item) => validate(item, schema.items, depth + 1))
            )
        }
        if (schema.type === 'string')
            return (
                typeof value === 'string' &&
                value.length <= (schema.maxLength ?? 500)
            )
        if (schema.type === 'boolean') return typeof value === 'boolean'
        if (schema.type === 'number' || schema.type === 'integer') {
            return (
                typeof value === 'number' &&
                Number.isFinite(value) &&
                (schema.type !== 'integer' || Number.isInteger(value)) &&
                value >= (schema.minimum ?? -Infinity) &&
                value <= (schema.maximum ?? Infinity)
            )
        }
        return false
    }
    const append = (params, key, value) => {
        if (value !== null && typeof value === 'object') {
            Object.entries(value).forEach(([child, item]) =>
                append(params, `${key}[${child}]`, item),
            )
        } else params.append(key, String(value))
    }
    const result = (value) => ({
        content: [{ type: 'text', text: JSON.stringify(value) }],
    })
    const hasControlCharacters = (value) =>
        [...value].some((character) => {
            const code = character.charCodeAt(0)
            return code <= 31 || code === 127
        })
    const sameOriginEndpoint = (value) => {
        if (
            typeof value !== 'string' ||
            !value.startsWith('/') ||
            value.startsWith('//') ||
            hasControlCharacters(value)
        )
            return false
        try {
            return new URL(value, location.origin).origin === location.origin
        } catch {
            return false
        }
    }
    const sameOriginFormAction = (form) => {
        try {
            const action = new URL(
                form.getAttribute?.('action') ?? form.action ?? location.href,
                location.href ?? location.origin,
            )
            return action.origin === location.origin
        } catch {
            return false
        }
    }
    const formBinding = (binding, schema) => {
        if (
            !binding ||
            binding.type !== 'form' ||
            typeof binding.target !== 'string' ||
            !/^[A-Za-z][A-Za-z0-9_-]{0,127}$/.test(binding.target) ||
            !schema ||
            schema.type !== 'object' ||
            !schema.properties ||
            typeof schema.properties !== 'object' ||
            schema.additionalProperties !== false
        )
            return null
        const form = document.getElementById?.(binding.target)
        if (
            !form ||
            String(form.tagName).toUpperCase() !== 'FORM' ||
            typeof form.requestSubmit !== 'function' ||
            typeof form.addEventListener !== 'function'
        )
            return null
        if (!sameOriginFormAction(form)) return null
        return form
    }
    const fieldControls = (form, name) => {
        const named = form.elements?.namedItem?.(name)
        if (!named) return []
        if (typeof named.tagName === 'string') return [named]
        if (typeof named.length !== 'number') return []
        return Array.from(named).filter((control) => control)
    }
    const protectedControl = (control) => {
        const tag = String(control.tagName ?? '').toUpperCase()
        const type = String(control.type ?? '').toLowerCase()
        return (
            tag === 'INPUT' &&
            [
                'hidden',
                'password',
                'file',
                'submit',
                'button',
                'reset',
                'image',
            ].includes(type)
        )
    }
    const supportedControl = (control) =>
        ['INPUT', 'SELECT', 'TEXTAREA'].includes(
            String(control.tagName ?? '').toUpperCase(),
        )
    const disabledControl = (control) =>
        control.disabled || control.readOnly || control.matches?.(':disabled')
    const controlChoices = (control) =>
        Array.from(control.options ?? []).map((option) => String(option.value))
    const validControlValue = (control, value) => {
        const tag = String(control.tagName ?? '').toUpperCase()
        const type = String(control.type ?? '').toLowerCase()
        const values = Array.isArray(value)
            ? value.map(String)
            : [String(value)]
        if (tag === 'SELECT') {
            const choices = controlChoices(control)
            return values.every((item) => choices.includes(item))
        }
        if (type === 'checkbox' && !Array.isArray(value))
            return typeof value === 'boolean' || typeof value === 'string'
        return true
    }
    const formAssignments = (form, input, schema) => {
        if (
            !input ||
            typeof input !== 'object' ||
            Array.isArray(input) ||
            !validate(input, schema) ||
            Object.keys(input).some(
                (name) => !Object.hasOwn(schema.properties, name),
            )
        )
            throw new Error('Invalid form input.')
        return Object.entries(input).map(([name, value]) => {
            const controls = fieldControls(form, name)
            const radioGroup =
                controls.length > 0 &&
                controls.every(
                    (control) =>
                        String(control.type ?? '').toLowerCase() === 'radio',
                )
            if (
                controls.length === 0 ||
                (radioGroup &&
                    (Array.isArray(value) ||
                        !controls.some(
                            (control) =>
                                String(control.value ?? 'on') === String(value),
                        ))) ||
                controls.some(
                    (control) =>
                        !supportedControl(control) ||
                        protectedControl(control) ||
                        disabledControl(control) ||
                        !validControlValue(control, value),
                )
            )
                throw new Error('Invalid form field.')
            return { name, value, controls }
        })
    }
    const setControlValue = (control, value) => {
        const type = String(control.type ?? '').toLowerCase()
        const values = Array.isArray(value)
            ? value.map(String)
            : [String(value)]
        if (type === 'checkbox' || type === 'radio') {
            if (typeof value === 'boolean' && type === 'checkbox')
                control.checked = value
            else
                control.checked = values.includes(String(control.value ?? 'on'))
            return
        }
        if (
            String(control.tagName ?? '').toUpperCase() === 'SELECT' &&
            control.multiple
        ) {
            Array.from(control.options ?? []).forEach(
                (option) =>
                    (option.selected = values.includes(String(option.value))),
            )
            return
        }
        control.value = values[0] ?? ''
    }
    const dispatchControlEvents = (control) => {
        if (
            typeof control.dispatchEvent !== 'function' ||
            typeof globalThis.Event !== 'function'
        )
            return
        for (const type of ['input', 'change'])
            control.dispatchEvent(new globalThis.Event(type, { bubbles: true }))
    }
    const submitForm = async (form, input, schema, confirmationMessage) => {
        const assignments = formAssignments(form, input, schema)
        const preview = JSON.stringify(input)
        if (preview.length > 4000) throw new Error('Invalid form input.')
        if (typeof globalThis.confirm !== 'function')
            return result({ status: 'pending' })
        if (
            !globalThis.confirm(
                `${confirmationMessage}\n\n${JSON.stringify(input, null, 2)}`,
            )
        )
            return result({ status: 'pending', cancelled: true })
        if (!sameOriginFormAction(form)) throw new Error('Invalid form target.')
        assignments.forEach(({ value, controls }) => {
            if (controls.length === 1) setControlValue(controls[0], value)
            else if (
                Array.isArray(value) &&
                controls.every((control) =>
                    ['checkbox', 'radio'].includes(
                        String(control.type ?? '').toLowerCase(),
                    ),
                )
            )
                controls.forEach((control) => setControlValue(control, value))
            else if (Array.isArray(value))
                controls.forEach((control, index) =>
                    setControlValue(control, value[index] ?? ''),
                )
            else controls.forEach((control) => setControlValue(control, value))
            controls.forEach(dispatchControlEvents)
        })
        let submitEvent
        const observeSubmit = (event) => {
            submitEvent = event
        }
        form.addEventListener('submit', observeSubmit)
        try {
            form.requestSubmit()
        } finally {
            form.removeEventListener?.('submit', observeSubmit)
        }
        return result({
            status:
                submitEvent && !submitEvent.defaultPrevented
                    ? 'submitted'
                    : 'pending',
        })
    }

    const registerManifest = (manifest, node, controller) => {
        for (const tool of manifest.tools) {
            if (owners.has(tool.name)) continue
            if (
                typeof tool.name !== 'string' ||
                typeof tool.description !== 'string'
            )
                continue
            const form =
                tool.effect === 'write'
                    ? formBinding(tool.binding, tool.inputSchema)
                    : null
            if (tool.effect !== 'read' && !form) continue
            const confirmationMessage =
                typeof manifest.messages?.confirmForm === 'string' &&
                manifest.messages.confirmForm.length > 0 &&
                manifest.messages.confirmForm.length <= 200 &&
                !hasControlCharacters(manifest.messages.confirmForm)
                    ? manifest.messages.confirmForm
                    : 'Submit this form?'
            const typedEndpoint = tool.binding?.type === 'endpoint'
            const binding =
                typeof tool.binding === 'string'
                    ? tool.binding
                    : tool.binding?.type === 'inline' &&
                        tool.binding?.target === 'page'
                      ? 'inline'
                      : tool.binding?.type === 'endpoint'
                        ? tool.binding.target
                        : null
            const inline = binding === 'inline'
            if (
                !form &&
                !inline &&
                !(typedEndpoint
                    ? sameOriginEndpoint(binding)
                    : /^\/agent\/v1\/(pages|search|navigation|taxonomies(?:\/[a-zA-Z0-9._-]+\/terms)?)$/.test(
                          binding,
                      ))
            )
                continue
            try {
                owners.set(tool.name, node)
                const registration = context.registerTool(
                    {
                        name: tool.name,
                        description: tool.description,
                        inputSchema: tool.inputSchema,
                        annotations: { readOnlyHint: tool.effect === 'read' },
                        async execute(input = {}) {
                            if (
                                controller.signal.aborted ||
                                form?.isConnected === false
                            )
                                throw new Error(
                                    'This tool is no longer available.',
                                )
                            if (form)
                                return submitForm(
                                    form,
                                    input,
                                    tool.inputSchema,
                                    confirmationMessage,
                                )
                            if (!validate(input, tool.inputSchema))
                                throw new Error('Invalid tool input.')
                            if (inline) return result(graph())
                            const url = new URL(binding, location.origin)
                            Object.entries(input).forEach(([key, value]) =>
                                append(url.searchParams, key, value),
                            )
                            const response = await fetch(url.href, {
                                method: 'GET',
                                credentials: 'omit',
                                redirect: 'error',
                                headers: { Accept: 'application/json' },
                                signal: controller.signal,
                            })
                            if (!response.ok)
                                throw new Error('Unable to read site data.')
                            return result(await response.json())
                        },
                    },
                    { signal: controller.signal },
                )
                // Both synchronous origin-trial APIs and current promise APIs exist.
                registration?.catch?.(() => {})
            } catch {
                // Unsupported/disabled policy and duplicate registrations are silent.
            }
        }
    }

    const scan = () => {
        const nodes = document.querySelectorAll
            ? [...document.querySelectorAll('[data-capell-agent-tools]')]
            : [document.querySelector('[data-capell-agent-tools]')].filter(
                  Boolean,
              )
        for (const [node, registration] of registrations) {
            if (nodes.includes(node) && node.textContent === registration.text)
                continue
            registration.controller.abort()
            for (const [name, owner] of owners) {
                if (owner !== node) continue
                owners.delete(name)
                if (typeof context.unregisterTool === 'function') {
                    try {
                        context.unregisterTool(name)
                    } catch {
                        /* Legacy API may have already removed it. */
                    }
                }
            }
            registrations.delete(node)
        }
        for (const node of nodes) {
            if (registrations.has(node)) continue
            let manifest
            try {
                manifest = JSON.parse(node.textContent)
            } catch {
                continue
            }
            if (
                manifest?.capellAgentSchema !== 1 ||
                !Array.isArray(manifest.tools)
            )
                continue
            const controller = new AbortController()
            registrations.set(node, { text: node.textContent, controller })
            registerManifest(manifest, node, controller)
        }
    }
    scan()
    if (typeof MutationObserver === 'function' && document.documentElement) {
        new MutationObserver(scan).observe(document.documentElement, {
            childList: true,
            subtree: true,
            characterData: true,
        })
    }
})()
