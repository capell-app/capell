const fs = require('node:fs')
const input = JSON.parse(fs.readFileSync(0, 'utf8'))
const relativeStatePath = input.statePath.endsWith('.meta.slug')
    ? 'meta.slug'
    : 'slug'
const state = {
    slug_auto_update_disabled: input.manual,
}
const watchers = []
const get = (path) => state[path]
const set = (path, value) => {
    state[path] = value
    if (path === relativeStatePath)
        watchers.forEach((watcher) => watcher(value))
}
const wire = { watch: (path, watcher) => watchers.push(watcher), $hook() {} }
const editor = new Function(
    '$get',
    '$set',
    '$wire',
    `return (${input.alpine})`,
)(get, set, wire)
Object.assign(editor, {
    $get: get,
    $set: set,
    $wire: wire,
    $nextTick: (callback) => callback(),
    $refs: {},
})
state[relativeStatePath] = editor.initialState ?? ''
editor.init()
for (const step of input.steps) {
    editor.initModification()
    if (step.interaction === 'automatic' || input.wireModel) {
        set(relativeStatePath, step.slug)
    }
    if (step.interaction !== 'automatic') {
        if (input.input) {
            new Function('$event', `with (this) { ${input.input} }`).call(
                editor,
                {
                    target: { value: step.slug },
                },
            )
        }
    }
    switch (step.interaction) {
        case 'ok':
            editor.submitModification()
            break
        case 'enter':
            new Function(`with (this) { ${input.enter} }`).call(editor)
            break
        case 'escape':
            new Function(`with (this) { ${input.escape} }`).call(editor)
            break
        case 'cancel':
            editor.cancelModification()
            break
        case 'reset':
            editor.resetModification()
            editor.submitModification()
            break
    }
}
process.stdout.write(
    JSON.stringify({
        slug: editor.currentSlug(),
        manual: state.slug_auto_update_disabled,
        editing: editor.editing,
        modified: editor.modified,
        visitUrl: input.visitUrl,
        visitText:
            input.visitUrl === null
                ? null
                : input.linkParts
                      .map((part) =>
                          part.expression
                              ? new Function(
                                    `with (this) { return (${part.expression}) }`,
                                ).call(editor)
                              : part.text,
                      )
                      .join(''),
    }),
)
