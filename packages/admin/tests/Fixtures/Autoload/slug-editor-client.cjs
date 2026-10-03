const fs = require('node:fs')
const input = JSON.parse(fs.readFileSync(0, 'utf8'))
const state = {
    'meta.slug': '',
    slug: '',
    slug_auto_update_disabled: input.manual,
}
const get = (path) => state[path]
const set = (path, value) => {
    state[path] = value
}
const wire = { watch() {}, $hook() {} }
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
editor.initModification()
state['meta.slug'] = input.slug
state.slug = input.slug
if (input.interaction !== 'automatic' && input.input) {
    new Function('$event', `with (this) { ${input.input} }`).call(editor, {
        target: { value: input.slug },
    })
}
switch (input.interaction) {
    case 'ok':
        editor.submitModification()
        break
    case 'enter':
        new Function(`with (this) { ${input.enter} }`).call(editor)
        break
    case 'cancel':
        editor.cancelModification()
        break
    case 'reset':
        editor.resetModification()
        break
}
process.stdout.write(
    JSON.stringify({
        slug: editor.currentSlug(),
        manual: state.slug_auto_update_disabled,
        editing: editor.editing,
        modified: editor.modified,
    }),
)
