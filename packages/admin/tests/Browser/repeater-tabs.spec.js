import { expect, test } from '@playwright/test'
import { readFile } from 'node:fs/promises'

const source = await readFile(
    new URL('../../resources/js/components/repeater-tabs.js', import.meta.url),
    'utf8',
)
const moduleUrl = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`

async function deleteFinalTranslation(page, focusTabFirst) {
    const errors = []
    page.on('pageerror', (error) => errors.push(error.message))
    await page.setContent(`
        <div id="editor">
            <div role="tablist" aria-label="Translations">
                <button role="tab" data-tab-index="1" aria-selected="true">English</button>
                <button id="actions">Actions</button>
                <button id="delete" hidden>Delete translation</button>
            </div>
        </div>
    `)
    // Exercise the source module with native browser focus and a deferred DOM replacement.
    // Livewire publishing, entanglement and real editor acceptance remain integration gates.
    await page.evaluate(async (url) => {
        const { default: repeaterTabs } = await import(url)
        const editor = document.querySelector('#editor')
        const tablist = editor.querySelector('[role="tablist"]')
        const component = Object.assign(repeaterTabs({ activeTab: 1 }), {
            $refs: { tablist },
            $nextTick: (callback) => requestAnimationFrame(callback),
        })
        tablist.addEventListener('keydown', (event) => component.keydown(event))
        tablist.addEventListener('focusin', () => {
            component.tablistHasFocus = true
        })
        tablist.addEventListener('focusout', (event) => component.blur(event))
        tablist.querySelector('[role="tab"]').addEventListener('focus', () => {
            component.focusedTab = 1
        })
        const deleteButton = editor.querySelector('#delete')
        editor.querySelector('#actions').addEventListener('click', () => {
            deleteButton.hidden = false
            deleteButton.focus()
        })
        deleteButton.addEventListener('click', () => {
            tablist.remove()
            delete component.$refs.tablist
            component.refresh(0)
            const emptyState = document.createElement('div')
            const add = document.createElement('button')
            add.textContent = 'Add translation'
            emptyState.append(add)
            editor.append(emptyState)
            component.$refs.emptyState = emptyState
        })
    }, moduleUrl)

    if (focusTabFirst) {
        await page.getByRole('tab', { name: 'English' }).focus()
        await page.keyboard.press('Tab')
    } else {
        await page.getByRole('button', { name: 'Actions' }).focus()
    }
    await expect(page.getByRole('button', { name: 'Actions' })).toBeFocused()
    await page.keyboard.press('Enter')
    await expect(
        page.getByRole('button', { name: 'Delete translation' }),
    ).toBeFocused()
    await page.keyboard.press('Enter')

    await expect(page.getByRole('tablist')).toHaveCount(0)
    await expect(
        page.getByRole('button', { name: 'Add translation' }),
    ).toBeFocused()
    expect(errors).toEqual([])
}

test('keyboard deletion of the final translation focuses Add translation after rendering', async ({
    page,
}) => {
    await deleteFinalTranslation(page, true)
})

test('keyboard deletion recovers focus when entering Actions without first focusing a tab', async ({
    page,
}) => {
    await deleteFinalTranslation(page, false)
})
