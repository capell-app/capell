import { expect, test } from '@playwright/test'
import { readFile } from 'node:fs/promises'

const source = await readFile(
    new URL(
        '../../resources/js/components/keyboard-shortcuts.js',
        import.meta.url,
    ),
    'utf8',
)
const moduleUrl = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`

async function mountShortcuts(page) {
    await page.setContent(`
        <button id="outside">Outside</button>
        <button id="destination">Destination</button>
        <input aria-label="Input">
        <textarea aria-label="Textarea"></textarea>
        <select aria-label="Select"><option>Option</option></select>
        <div contenteditable="true" aria-label="Editable"></div>
    `)
    // Exercise the source module with native browser focus and keyboard events.
    // Livewire wiring and real editor acceptance remain integration gates.
    await page.evaluate(async (url) => {
        const { default: keyboardShortcuts } = await import(url)
        const component = keyboardShortcuts([
            { key: '/', action: 'focus', target: '#destination' },
        ])
        component.init()
        window.shortcutEvents = []
        window.addEventListener('keydown', (event) => {
            window.shortcutEvents.push({
                key: event.key,
                prevented: event.defaultPrevented,
            })
        })
    }, moduleUrl)
}

test('modified shortcuts are ignored and do not prevent their default action', async ({
    page,
}) => {
    await mountShortcuts(page)
    for (const modifier of ['Control', 'Meta', 'Alt']) {
        await page.getByRole('button', { name: 'Outside' }).focus()
        await page.keyboard.press(`${modifier}+/`)
        await expect(
            page.getByRole('button', { name: 'Outside' }),
        ).toBeFocused()
        const event = await page.evaluate(() => window.shortcutEvents.at(-1))
        expect(event).toEqual({ key: '/', prevented: false })
    }
})

test('shortcuts do not fire while editing form fields or editable content', async ({
    page,
}) => {
    await mountShortcuts(page)
    for (const name of ['Input', 'Textarea', 'Select', 'Editable']) {
        const field = page.getByLabel(name)
        await field.focus()
        await page.keyboard.press('/')
        await expect(field).toBeFocused()
        const event = await page.evaluate(() => window.shortcutEvents.at(-1))
        expect(event).toEqual({ key: '/', prevented: false })
    }
    await page.getByRole('button', { name: 'Outside' }).focus()
    await page.keyboard.press('/')
    await expect(
        page.getByRole('button', { name: 'Destination' }),
    ).toBeFocused()
    const event = await page.evaluate(() => window.shortcutEvents.at(-1))
    expect(event).toEqual({ key: '/', prevented: true })
})
