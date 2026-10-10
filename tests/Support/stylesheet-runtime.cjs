const fs = require('node:fs')
const path = require('node:path')
const { transform } = require('lightningcss')
const { compile } = require('tailwindcss')

async function inspect() {
    const filename = process.argv[2]
    let css = fs.readFileSync(filename, 'utf8')
    let sources = []
    const stylesheets = JSON.parse(process.argv[5] || '{}')
    if (process.argv[3] === 'tailwind') {
        const compiled = await compile(css, {
            base: path.dirname(filename),
            loadStylesheet: async (id, base) => {
                if (Object.hasOwn(stylesheets, id)) return { path: id, base, content: stylesheets[id] }
                const absoluteId = path.resolve(base, id)
                if (Object.hasOwn(stylesheets, absoluteId)) return { path: absoluteId, base: path.dirname(absoluteId), content: stylesheets[absoluteId] }
                // Synthetic host imports are file boundaries, not the generator under test.
                if (['@acme/base.css', 'resources/css/global.css', './ok.css'].includes(id)) {
                    return { path: id, base, content: '.imported-host-style { display: block; }' }
                }
                const resolved = id.startsWith('.') ? path.resolve(base, id) : require.resolve(id === 'tailwindcss' ? 'tailwindcss/index.css' : id, { paths: [base, process.cwd()] })
                return { path: resolved, base: path.dirname(resolved), content: fs.readFileSync(resolved, 'utf8') }
            },
            loadModule: async (id, base, hint) => ({
                path: id, base,
                module: hint === 'config' ? {} : ({ addUtilities }) => {
                    addUtilities({ ['.plugin-' + id.replace(/[^a-z0-9]/gi, '-')]: { display: 'block' } })
                },
            }),
        })
        sources = compiled.sources.map(source => ({ ...source, base: path.resolve(source.base) }))
        css = compiled.build(JSON.parse(process.argv[4]))
    }
    const rules = []
    const layers = []
    const tokens = {}
    const tokenValue = token => {
        if (token.type === 'color' && token.value.type === 'rgb') {
            return '#' + ['r', 'g', 'b'].map(channel => token.value[channel].toString(16).padStart(2, '0')).join('')
        }
        if (token.type === 'length') return String(token.value.value) + token.value.unit
        if (token.type === 'token') return typeof token.value.value === 'number' ? String(Number(token.value.value.toPrecision(7))) : String(token.value.value ?? '')
        return ''
    }
    const collect = (value, variables, gradients) => {
        if (!value || typeof value !== 'object') return
        if (value.type === 'var') variables.push(value.value.name.ident)
        if (String(value.type).includes('gradient')) gradients.push(value.type)
        for (const child of Object.values(value)) collect(child, variables, gradients)
    }
    transform({ filename, code: Buffer.from(css), visitor: { Rule: {
        style: rule => {
            const classes = rule.value.selectors.flatMap(selector => selector.filter(part => part.type === 'class').map(part => part.name))
            const declarations = rule.value.declarations.declarations.map(declaration => {
                const variables = [], gradients = []
                collect(declaration, variables, gradients)
                return { property: declaration.property === 'unparsed' ? declaration.value.propertyId.property : declaration.property,
                    variables, gradients, value: declaration.value }
            })
            for (const declaration of rule.value.declarations.declarations) {
                if (declaration.property === 'custom') tokens[declaration.value.name] = declaration.value.value.map(tokenValue).join('').trim()
            }
            const root = rule.value.selectors.some(selector => selector.some(part => part.type === 'pseudo-class' && part.kind === 'root'))
            rules.push({ classes, declarations, root })
        },
        'layer-statement': rule => { layers.push(...rule.value.names.map(name => name.join('.'))) },
    } } })
    console.log(JSON.stringify({ rules, layers, sources, tokens }))
}

inspect().catch(error => { console.error(error); process.exitCode = 1 })
