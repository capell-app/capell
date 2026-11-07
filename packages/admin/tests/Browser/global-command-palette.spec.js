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

// Use the authenticated Testbench workbench for the Livewire/Alpine integration.
// Set CAPELL_PALETTE_STORAGE_STATE to a Playwright storage-state file, or provide
// CAPELL_PALETTE_EMAIL and CAPELL_PALETTE_PASSWORD for the workbench login.
async function openWorkbench(page) {
    const baseUrl = process.env.CAPELL_PALETTE_BASE_URL
    expect(
        baseUrl,
        'CAPELL_PALETTE_BASE_URL must point to the Testbench workbench',
    ).toBeTruthy()
    await page.goto(`${baseUrl}/admin`)
    if (new URL(page.url()).pathname.endsWith('/login')) {
        await page
            .getByLabel(/email/i)
            .first()
            .fill(process.env.CAPELL_PALETTE_EMAIL ?? '')
        await page
            .locator('input[type="password"]')
            .fill(process.env.CAPELL_PALETTE_PASSWORD ?? '')
        await page.locator('button[type="submit"]').click()
        await page.waitForURL((url) => !url.pathname.endsWith('/login'))
    }
    await expect(page.locator('[data-command-palette]')).toBeAttached()
}

test.describe('workbench palette focus restoration', () => {
    test.use({ storageState: process.env.CAPELL_PALETTE_STORAGE_STATE })

    for (const close of [
        'Escape',
        'close button',
        'backdrop',
        'Mod+K from the search input',
    ]) {
        test(`returns focus to the opener after ${close}`, async ({ page }) => {
            await openWorkbench(page)
            const opener = page
                .locator('.fi-sidebar-nav a[href]:visible')
                .first()
            await opener.focus()
            await page.evaluate(() => {
                window.paletteOpener = document.activeElement
            })
            await page.keyboard.press('ControlOrMeta+k')
            const dialog = page.getByRole('dialog', {
                name: 'Command palette',
                exact: true,
            })
            await expect(dialog).toBeVisible()
            await expect(
                page.getByLabel('Search commands', { exact: true }),
            ).toBeFocused()
            if (close === 'Escape') {
                // First close from the input, then from another trapped control.
                await page.keyboard.press('Escape')
                await expect(dialog).toBeHidden()
                await expect
                    .poll(() =>
                        page.evaluate(
                            () =>
                                document.activeElement === window.paletteOpener,
                        ),
                    )
                    .toBe(true)
                await expect
                    .poll(() =>
                        page.evaluate(() => {
                            const root = document.querySelector(
                                '[data-command-palette]',
                            )
                            return JSON.parse(
                                root.getAttribute('wire:snapshot'),
                            ).data.open
                        }),
                    )
                    .toBe(false)
                await page.keyboard.press('ControlOrMeta+k')
                await expect(dialog).toBeVisible()
                await page.keyboard.press('Tab')
                await page.keyboard.press('Escape')
            } else if (close === 'close button') {
                await dialog
                    .getByRole('button', {
                        name: 'Close command palette',
                        exact: true,
                    })
                    .click()
            } else if (close === 'Mod+K from the search input') {
                // Focus is still in the palette's own search input here.
                await page.keyboard.press('ControlOrMeta+k')
            } else {
                await page
                    .locator('[data-command-palette] > div')
                    .first()
                    .click({ position: { x: 5, y: 5 } })
            }
            await expect(dialog).toBeHidden()
            await expect
                .poll(() =>
                    page.evaluate(
                        () => document.activeElement === window.paletteOpener,
                    ),
                )
                .toBe(true)
            // The snapshot changes after Livewire processes the response/morph.
            await expect
                .poll(() =>
                    page.evaluate(() => {
                        const root = document.querySelector(
                            '[data-command-palette]',
                        )
                        return JSON.parse(root.getAttribute('wire:snapshot'))
                            .data.open
                    }),
                )
                .toBe(false)
            await expect(opener).toBeFocused()
        })
    }
})
