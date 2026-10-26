import assert from 'node:assert/strict'
import test from 'node:test'
import repeaterTabs from '../../resources/js/components/repeater-tabs.js'

function fixture(t, direction = 'ltr') {
    const previousDocument = globalThis.document
    const document = { activeElement: null, body: {} }
    globalThis.document = document
    t.after(() => {
        globalThis.document = previousDocument
    })
    const tablist = {
        querySelectorAll: () => tabs,
        contains: (element) => tabs.includes(element),
        ownerDocument: {
            defaultView: { getComputedStyle: () => ({ direction }) },
        },
    }
    const tabs = [1, 2, 3].map((index) => ({
        dataset: { tabIndex: String(index) },
        closest: () => tablist,
        focus() {
            document.activeElement = this
        },
    }))
    const component = Object.assign(repeaterTabs({ activeTab: 1 }), {
        $refs: { tablist },
        $nextTick: (callback) => callback(),
    })
    function key(key, target = document.activeElement, modifiers = {}) {
        let prevented = false
        component.keydown({
            key,
            target,
            ...modifiers,
            preventDefault() {
                prevented = true
            },
        })
        return prevented
    }
    tabs[0].focus()
    return { component, tabs, document, key, tablist }
}

test('arrows wrap focus while selection waits for Enter or Space', (t) => {
    const { component, tabs, document, key } = fixture(t)
    assert.equal(key('ArrowLeft'), true)
    assert.equal(document.activeElement, tabs[2])
    assert.equal(component.focusedTab, 3)
    assert.equal(component.tab, 1)
    assert.equal(key('Enter'), true)
    assert.equal(component.tab, 3)
    assert.equal(key('ArrowRight'), true)
    assert.equal(document.activeElement, tabs[0])
    assert.equal(component.tab, 3)
    assert.equal(key(' '), true)
    assert.equal(component.tab, 1)
})

test('RTL arrows follow visual neighbours and wrap without activating until Enter or Space', (t) => {
    const { component, tabs, document, key } = fixture(t, 'rtl')
    tabs[1].focus()
    component.activate(2)
    assert.equal(key('ArrowRight'), true)
    assert.equal(document.activeElement, tabs[0])
    assert.equal(component.tab, 2)
    key('ArrowRight')
    assert.equal(document.activeElement, tabs[2])
    assert.equal(component.tab, 2)
    key('Enter')
    assert.equal(component.tab, 3)
    key('ArrowLeft')
    assert.equal(document.activeElement, tabs[0])
    assert.equal(component.tab, 3)
    key(' ')
    assert.equal(component.tab, 1)
    key('ArrowLeft')
    assert.equal(document.activeElement, tabs[1])
    assert.equal(component.tab, 1)
})

test('LTR arrows move between neighbours without activating the focused tab', (t) => {
    const { component, tabs, document, key } = fixture(t)
    tabs[1].focus()
    component.activate(2)
    key('ArrowLeft')
    assert.equal(document.activeElement, tabs[0])
    assert.equal(component.tab, 2)
    key('ArrowRight')
    assert.equal(document.activeElement, tabs[1])
    assert.equal(component.tab, 2)
})

test('Home and End focus the first and last tabs without changing content', (t) => {
    const { component, tabs, document, key } = fixture(t)
    key('End')
    assert.equal(document.activeElement, tabs[2])
    assert.equal(component.focusedTab, 3)
    key('Home')
    assert.equal(document.activeElement, tabs[0])
    assert.equal(component.focusedTab, 1)
    assert.equal(component.tab, 1)
})

test('keyboard events from menus nested repeaters and fields are left alone', (t) => {
    const { component, tabs, key } = fixture(t)
    assert.equal(key('ArrowRight', { tagName: 'INPUT' }), false)
    assert.equal(key('Enter', { tagName: 'BUTTON' }), false)
    assert.equal(key('Tab', tabs[0]), false)
    assert.equal(key('ArrowRight', tabs[0], { ctrlKey: true }), false)
    assert.equal(component.tab, 1)
    assert.equal(component.focusedTab, null)
})

test('refreshing languages preserves tab focus and selects the surviving tab', (t) => {
    const { component, tabs, document, key } = fixture(t)
    key('End')
    tabs.pop()
    document.activeElement = document.body
    component.refresh(2)
    assert.equal(component.tab, 2)
    assert.equal(component.focusedTab, 2)
    assert.equal(document.activeElement, tabs[1])
})

test('refreshing languages leaves focus in an active content field', (t) => {
    const { component, document } = fixture(t)
    const field = { value: 'Unsaved French text' }
    document.activeElement = field
    component.refresh(2)
    assert.equal(component.tab, 2)
    assert.equal(document.activeElement, field)
    assert.equal(field.value, 'Unsaved French text')
})

test('deleting the final tab focuses the empty-state add control after rendering', (t) => {
    const { component, tabs, document, key } = fixture(t)
    key('End')
    tabs.splice(0)
    delete component.$refs.tablist
    document.activeElement = document.body
    let render
    component.$nextTick = (callback) => {
        render = callback
    }
    component.refresh(0)
    assert.equal(document.activeElement, document.body)
    const addTranslation = {
        focus() {
            document.activeElement = this
        },
    }
    component.$refs.emptyState = { querySelector: () => addTranslation }
    render()
    assert.equal(document.activeElement, addTranslation)
    assert.equal(component.tab, 1)
    assert.equal(component.focusedTab, null)
})

test('an empty shared projection does not steal focus from another editor', (t) => {
    const { component, document } = fixture(t)
    delete component.$refs.tablist
    const field = { value: 'Unsaved text' }
    document.activeElement = field
    const addTranslation = {
        focus() {
            document.activeElement = this
        },
    }
    component.$refs.emptyState = { querySelector: () => addTranslation }
    component.refresh(0)
    assert.equal(document.activeElement, field)
    assert.equal(field.value, 'Unsaved text')
})

test('deleting from the actions menu recovers focus even without first focusing a tab', (t) => {
    const { component, document } = fixture(t)
    component.tablistHasFocus = true
    delete component.$refs.tablist
    document.activeElement = document.body
    const addTranslation = {
        focus() {
            document.activeElement = this
        },
    }
    component.$refs.emptyState = { querySelector: () => addTranslation }
    component.refresh(0)
    assert.equal(document.activeElement, addTranslation)
    assert.equal(component.tablistHasFocus, false)
})

test('nested tablists do not participate in the parent focus order', (t) => {
    const { component, tabs, document, key } = fixture(t)
    tabs.push({ closest: () => ({}), dataset: { tabIndex: '1' } })
    key('End')
    assert.equal(component.tabs().length, 3)
    assert.equal(document.activeElement, tabs[2])
})

test('leaving the tablist restores the selected tab as the next tab stop', (t) => {
    const { component, key } = fixture(t)
    component.tablistHasFocus = true
    key('End')
    component.blur({ relatedTarget: null })
    assert.equal(component.focusedTab, 3)
    component.blur({ relatedTarget: { tagName: 'INPUT' } })
    assert.equal(component.focusedTab, null)
    assert.equal(component.tablistHasFocus, false)
    assert.equal(component.tab, 1)
})
