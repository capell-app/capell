#!/usr/bin/env node

const fs = require('node:fs')
const path = require('node:path')

const { redactText } = require('./failure-evidence')

const [outputPath, ...inputPaths] = process.argv.slice(2)

if (!outputPath || inputPaths.length === 0) {
    process.stderr.write(
        'Usage: redact-log.js <output-path> <input-path> [input-path ...]\n',
    )
    process.exitCode = 2
} else {
    const secretValues = JSON.parse(
        process.env.CAPELL_DIAGNOSTIC_SECRETS ?? '[]',
    )
    const contents = inputPaths
        .filter((inputPath) => fs.existsSync(inputPath))
        .map(
            (inputPath) =>
                `## ${inputPath.split('/').pop()}\n${fs.readFileSync(inputPath, 'utf8')}`,
        )
        .join('\n')

    fs.mkdirSync(path.dirname(outputPath), { recursive: true })
    const redacted = redactText(contents, secretValues).replace(
        /(["']?(?:stateToken|csrfToken|signedState|signedEditorUrl|signedAdminUrl)["']?\s*[:=]\s*)(["'])[^"']*\2/gi,
        '$1$2[redacted]$2',
    )

    fs.writeFileSync(outputPath, redacted)
}
