/** Private authoring bridge. Confirmation tokens never enter tool results. */
export default function agentAdminBridge(configuration) {
    let busy = false
    const controller = new AbortController()
    const result = (value) => ({
        content: [{ type: 'text', text: JSON.stringify(value) }],
    })
    const withoutToken = (value) => {
        const safe = { ...value }
        delete safe.confirmationToken
        return safe
    }
    const endpoint = (value) => {
        const url = new URL(value, location.origin)
        if (url.origin !== location.origin) throw new Error(configuration.error)
        return url.href
    }
    const request = async (payload) => {
        const response = await fetch(endpoint(configuration.invokeUrl), {
            method: 'POST',
            credentials: 'same-origin',
            redirect: 'error',
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': configuration.csrf,
            },
            body: JSON.stringify(payload),
        })
        if (!response.ok) throw new Error(configuration.error)
        return response.json()
    }
    const confirm = (preview) =>
        new Promise((resolve) => {
            const dialog = document.createElement('dialog')
            const heading = document.createElement('h2')
            heading.textContent = configuration.title
            const content = document.createElement('pre')
            // Content can include draft text. Never interpret it as HTML.
            content.textContent = JSON.stringify(preview.data, null, 2)
            const cancel = document.createElement('button')
            cancel.type = 'button'
            cancel.textContent = configuration.cancel
            const approve = document.createElement('button')
            approve.type = 'button'
            approve.textContent = configuration.approve
            const finish = (accepted) => {
                controller.signal.removeEventListener('abort', abort)
                dialog.remove()
                resolve(accepted)
            }
            const abort = () => finish(false)
            cancel.addEventListener('click', () => finish(false))
            approve.addEventListener('click', (event) => {
                // Synthetic clicks from page code are not human consent.
                if (event.isTrusted) finish(true)
            })
            dialog.addEventListener('cancel', (event) => {
                event.preventDefault()
                finish(false)
            })
            controller.signal.addEventListener('abort', abort, { once: true })
            dialog.append(heading, content, cancel, approve)
            dialog.style.cssText =
                'max-width:min(42rem,90vw);max-height:80vh;padding:1.5rem;border-radius:.75rem;overflow:auto'
            content.style.cssText =
                'white-space:pre-wrap;overflow-wrap:anywhere;max-height:50vh;overflow:auto;margin:1rem 0'
            cancel.style.cssText = 'padding:.5rem 1rem;margin-right:.75rem'
            approve.style.cssText = 'padding:.5rem 1rem;font-weight:600'
            document.body.append(dialog)
            dialog.showModal()
            cancel.focus()
        })
    return {
        async init() {
            const context = document.modelContext ?? navigator.modelContext
            if (typeof context?.registerTool !== 'function') return
            try {
                const response = await fetch(
                    endpoint(configuration.definitionsUrl),
                    {
                        credentials: 'same-origin',
                        redirect: 'error',
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    },
                )
                if (!response.ok) return
                const manifest = await response.json()
                if (
                    manifest.capellAgentSchema !== 1 ||
                    !Array.isArray(manifest.tools)
                )
                    return
                for (const tool of manifest.tools) {
                    await context.registerTool(
                        {
                            name: tool.name,
                            description: tool.description,
                            inputSchema: tool.inputSchema,
                            annotations: {
                                readOnlyHint: tool.effect === 'read',
                            },
                            async execute(payload = {}) {
                                if (busy) throw new Error(configuration.busy)
                                busy = true
                                try {
                                    const preview = await request({
                                        tool: tool.name,
                                        payload,
                                    })
                                    if (
                                        preview.mode !== 'confirmation_required'
                                    ) {
                                        return result(withoutToken(preview))
                                    }
                                    if (!(await confirm(preview)))
                                        return result({
                                            ok: false,
                                            mode: 'cancelled',
                                        })
                                    const response = await request({
                                        tool: tool.name,
                                        confirmation_token:
                                            preview.confirmationToken,
                                    })
                                    return result(withoutToken(response))
                                } finally {
                                    busy = false
                                }
                            },
                        },
                        { signal: controller.signal },
                    )
                }
            } catch {
                // Missing browser support or an expired session leaves ordinary UI usable.
            }
        },
        destroy() {
            controller.abort()
        },
    }
}
