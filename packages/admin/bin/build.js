import * as esbuild from 'esbuild'

async function compile(options) {
    const context = await esbuild.context(options)

    await context.rebuild()
    await context.dispose()
}

const defaultOptions = {
    define: {
        'process.env.NODE_ENV': `'production'`,
    },
    bundle: true,
    mainFields: ['module', 'main'],
    platform: 'neutral',
    sourcemap: false,
    sourcesContent: false,
    treeShaking: true,
    target: ['es2020'],
    minify: true,
}

const formComponents = [
    {
        entry: 'components/html-code-editor',
        output: 'components/html-code-editor',
    },
    {
        entry: 'components/keyboard-shortcuts',
        output: 'components/keyboard-shortcuts',
    },
    {
        entry: 'components/content-lock-heartbeat',
        output: 'components/content-lock-heartbeat',
    },
]

formComponents.forEach((component) => {
    compile({
        ...defaultOptions,
        platform: 'browser',
        format: 'esm',
        entryPoints: [`./resources/js/${component.entry}.js`],
        outfile: `./publishes/build/js/${component.output}.js`,
    })
})

const richContentPlugins = ['highlight']

richContentPlugins.forEach((plugin) => {
    compile({
        ...defaultOptions,
        entryPoints: [
            `./resources/js/filament/rich-content-plugins/${plugin}.js`,
        ],
        outfile: `./publishes/build/js/filament/rich-content-plugins/${plugin}.js`,
    })
})
