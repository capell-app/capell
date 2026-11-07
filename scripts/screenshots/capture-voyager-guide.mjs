import { verifyVoyagerRuntime } from './verify-voyager-runtime.mjs'

export default async function captureVoyagerGuide(session, entry) {
    const before = await verifyVoyagerRuntime()
    let observedFields = null
    const result = await session.capture({
        ...entry,
        beforeScreenshot: async (page, preparedEntry) => {
            if (typeof entry.beforeScreenshot === 'function') {
                await entry.beforeScreenshot(page, preparedEntry)
            }
            for (const group of entry.noticeFieldGroups ?? []) {
                let visible = true
                for (const label of group) {
                    const field = page.getByText(label, { exact: true }).first()
                    if (
                        (await field.count()) !== 1 ||
                        !(await field.isVisible())
                    )
                        visible = false
                }
                if (visible) {
                    observedFields = group
                    break
                }
            }
            if (!observedFields)
                throw new Error(
                    'The intended guide fields are not visible; the proposed screenshot remains unverified.',
                )
            if (entry.expectedTableRows !== undefined) {
                const rows = page.locator('table tbody tr')
                if ((await rows.count()) !== entry.expectedTableRows) {
                    throw new Error(
                        'The synthetic source page list has an unexpected row count.',
                    )
                }
            }
        },
    })
    const after = await verifyVoyagerRuntime()
    if (JSON.stringify(before) !== JSON.stringify(after)) {
        throw new Error(
            'Frozen-runtime identity changed during the capture; no accepted receipt can be issued.',
        )
    }
    result.evidence.capture.assertions.push(after, {
        name: 'guide-visible-fields',
        passed: true,
        fields: observedFields,
    })
    return result
}
