const fs = require('fs')
const path = require('path')

const root = process.cwd()

const paths = fs
    .readFileSync(0, 'utf8')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)

const packages = new Set()

for (const filePath of paths) {
    if (filePath === 'docs/screenshots.json') {
        packages.add('documentation')

        continue
    }

    const packageMatch = filePath.match(/^packages\/([^/]+)\//)

    if (
        packageMatch !== null &&
        fs.existsSync(
            path.join(
                root,
                'packages',
                packageMatch[1],
                'docs/screenshots.json',
            ),
        )
    ) {
        packages.add(packageMatch[1])
    }
}

for (const packageName of [...packages].sort()) {
    console.log(packageName)
}
