import { expect, test } from '@playwright/test'

// LAYOUT + PAINT parity test for the above-fold critical CSS.
//
// The active theme's critical CSS now inlines the brand @font-face declarations
// AND the brand colour custom properties (plus <link rel=preload> hints for the
// above-fold font weights). So a critical-only render (deferred stylesheets
// aborted) paints with the brand font + brand colours — true paint parity is
// achievable and asserted, not just layout. (Historically the brand font/colour
// lived only in the deferred build stylesheet, so a critical-only render fell
// back to a system font and lost brand colour, producing a 14–24% paint diff;
// that is no longer the case.)
//
// LAYOUT gates (1–2): capture a theme-agnostic structural "layout signature" of
// the above-fold elements in both modes and assert the HORIZONTAL geometry
// (left/width) of the full-bleed page landmarks (the top-level header / main /
// section that span the viewport) matches within a tight tolerance. Those boxes
// are sized by the containing block, not by text, so their horizontal geometry
// is font-independent — isolating real layout failures (grid→stack, full-width
// blowout, container shrink). Shrink-to-fit inline elements (a, button,
// headings) and nested/centered boxes are checked for presence/order only, and
// vertical geometry (top/height) is never asserted.
//
// PAINT gates (3–5): the header logo must keep its critical-CSS-driven size
// (not collapse to its ~16px intrinsic fallback) and match the full render
// (Gate 3); the hero H1 + logo must resolve the same font-family + colour as
// the full render — proving brand font/colour are applied, not a system
// fallback (Gate 4); and the always-present header band must achieve tolerant
// pixel parity between the two modes (Gate 5). Only the header band is asserted
// for pixels: the hero band below it intentionally defers its decorative
// gradient/grid for the LCP size budget, so a full-frame pixel diff would be
// dominated by that deferred (and intended) layer.

const baseUrl = (process.env.CAPELL_SMOKE_URL || 'http://capell.test').replace(
    /\/$/,
    '',
)
const homepageUrl = `${baseUrl}/?without_html_cache=1`

// Tolerance for horizontal geometry drift between critical-only and full
// renders. Small allowance for sub-pixel rounding; a real layout collapse
// blows far past this.
const horizontalTolerancePx = 2

// PAINT-parity guards (added on top of the layout gates).
//
// Why these are now meaningful: the active theme's critical CSS inlines the
// brand @font-face declarations (so the brand font loads even when deferred
// stylesheets are blocked) AND the brand colour custom properties. So a
// critical-only render now paints with the brand font + brand colours, not a
// system fallback. That makes true paint parity assertable -- but only for the
// regions whose paint actually lives in the critical CSS.
//
// The always-present brand region is the site header band (logo + nav + brand
// colours). Its critical-vs-full pixel diff is ~0.0-0.4% across every viewport.
// The hero band BELOW it intentionally defers its decorative gradient/grid for
// the LCP size budget, so a full-frame diff is dominated by that deferred layer
// (6-16% depending on viewport) and is NOT a parity signal. We therefore assert
// tight pixel parity over the header band only, and back it with DOM-level
// brand-paint parity (font-family + colour) and a logo-geometry guard.
const headerBandPx = 72
// Max share of header-band pixels allowed to differ. Sits ~5x above the
// observed worst case (0.39%) to absorb cross-machine anti-aliasing without
// letting a real brand-paint regression (font swap / lost colour) through.
const headerBandDiffRatioTolerance = 0.02
// A per-pixel RGB sum-of-abs-difference above this counts as "different".
// Tolerates anti-aliasing fringes; a font swap or colour change blows past it.
const pixelChannelThreshold = 60
// The brand logo is a viewBox'd <svg> sized purely by the critical CSS rule
// `.logo-glow :is(svg, img){height:var(--site-header-logo-height)}`. If that
// rule is dropped (e.g. a minifier merging the descendant combinator into a
// compound selector), the SVG collapses to its ~16px intrinsic fallback. Guard
// against that with an absolute floor as well as parity with the full render.
const minLogoHeightPx = 20
const logoGeometryTolerancePx = 2
// A box has font-INDEPENDENT horizontal geometry only when its width is set by
// the layout (the containing block), not by its text. On this page that means
// the full-bleed page landmarks (the top-level header / main / section that
// span the viewport at x≈0). Their left and width are identical in both modes.
//
// Everything else — shrink-to-fit inline elements (a, button, headings) AND
// nested/centered boxes (nav menus, hero images, card headers) — sizes or
// positions relative to font-width content. We still only geometry-assert the
// full-bleed landmarks: this gate stays font-agnostic so it cannot be tripped by
// any (current or future) font drift between the two modes — its job is layout
// collapse, while paint parity is covered separately by Gates 3–5. So we do NOT
// assert these boxes' pixel geometry; the structural gate (Gate 1: same
// elements, same order) covers them.
//
// A box counts as full-bleed when it spans (near) the whole viewport and starts
// at the left edge in the FULL render. A real layout failure (grid→stack,
// full-width blowout, container shrink) would change the width or left of these
// landmarks, which the gate catches.
function isFullBleed(entry, viewportWidth) {
    return (
        entry.x <= horizontalTolerancePx &&
        entry.width >= viewportWidth - horizontalTolerancePx
    )
}

const parityViewports = [
    { name: 'mobile', viewport: { width: 390, height: 844 } },
    { name: 'tablet', viewport: { width: 768, height: 1024 } },
    { name: 'desktop', viewport: { width: 1280, height: 900 } },
]

const disableAnimationsCss =
    '*{animation:none!important;transition:none!important;caret-color:transparent!important}'

async function skipWhenUnreachable(page) {
    const response = await page.request
        .get(homepageUrl, { failOnStatusCode: false, timeout: 15000 })
        .catch(() => null)

    if (response && response.status() < 500) {
        return
    }

    throw new Error(`CAPELL_SMOKE_URL is unreachable: ${homepageUrl}`)
}

async function settleAfterLoad(page) {
    await page.waitForLoadState('load')
    await page.evaluate(() => document.fonts.ready)
    await page.waitForTimeout(500)
}

// Capture a deterministic, theme-agnostic layout signature of the above-fold
// structural elements. Records only horizontal geometry + tag so the signature
// is stable across font/paint differences.
//
// Each entry carries a stable `domIndex` — its position in the full
// document-order match list — so the two modes can be aligned by element
// identity rather than by their position within the above-fold slice. The fold
// cutoff uses a generous margin (1.5x viewport height) because vertical
// positions shift slightly between the brand font (full) and the system-font
// fallback (critical-only); aligning by domIndex and intersecting means that
// font-driven boundary drift never adds/removes a logical element from the
// compared set.
async function captureLayoutSignature(page) {
    return page.evaluate(() => {
        const selector = 'header, nav, main, section, h1, h2, img, a, button'
        const round = (value) => Math.round(value)
        const foldMargin = window.innerHeight * 1.5

        return Array.from(document.querySelectorAll(selector))
            .map((element, domIndex) => ({
                domIndex,
                element,
                rect: element.getBoundingClientRect(),
            }))
            .filter(({ rect }) => rect.top < foldMargin && rect.width > 0)
            .slice(0, 20)
            .map(({ domIndex, element, rect }) => ({
                domIndex,
                tag: element.tagName.toLowerCase(),
                x: round(rect.left),
                width: round(rect.width),
            }))
    })
}

// Capture the brand paint signals that the critical CSS is responsible for: the
// header logo geometry + colour, and the hero H1's resolved font-family + colour.
// In a critical-only render these prove the brand font/colour are applied (not a
// system fallback) and the logo is sized (not collapsed to its 16px fallback).
async function captureBrandSignals(page) {
    return page.evaluate(() => {
        const round = (value) => Math.round(value)

        const logoSvg = document.querySelector(
            '[data-site-header] .logo-glow :is(svg, img), header .logo-glow :is(svg, img), header a[aria-label] svg',
        )
        const logo = logoSvg
            ? (() => {
                  const rect = logoSvg.getBoundingClientRect()
                  const style = getComputedStyle(logoSvg)

                  return {
                      width: round(rect.width),
                      height: round(rect.height),
                      color: style.color,
                  }
              })()
            : null

        const h1 = document.querySelector('h1')
        const heading = h1
            ? (() => {
                  const style = getComputedStyle(h1)

                  return { fontFamily: style.fontFamily, color: style.color }
              })()
            : null

        return { logo, heading }
    })
}

// Force any JS-driven scroll-reveal animation into its final visible state so the
// screenshot is deterministic and identical between the two modes (the reveal is
// IntersectionObserver-driven, not stylesheet-driven, so it can otherwise differ
// when stylesheets are blocked).
async function revealAllContent(page) {
    await page.evaluate(() => {
        document.documentElement.classList.remove('cr-on')
        document.querySelectorAll('[data-cr]').forEach((element) => {
            element.style.opacity = '1'
            element.style.transform = 'none'
        })
    })
    await page.waitForTimeout(120)
}

// Pixel-diff the top `bandHeight` px of two PNG screenshots using the browser's
// own PNG decoder + canvas (no external image-diff dependency). Returns the share
// of pixels whose RGB sum-of-abs-difference exceeds `pixelChannelThreshold`.
async function headerBandDiffRatio(
    comparatorPage,
    bufferA,
    bufferB,
    bandHeight,
) {
    return comparatorPage.evaluate(
        async ({ a, b, threshold, band }) => {
            const load = (src) =>
                new Promise((resolve, reject) => {
                    const image = new Image()
                    image.onload = () => resolve(image)
                    image.onerror = reject
                    image.src = src
                })

            const [imageA, imageB] = await Promise.all([load(a), load(b)])
            const width = Math.min(imageA.width, imageB.width)
            const height = Math.min(band, imageA.height, imageB.height)

            const pixels = (image) => {
                const canvas = document.createElement('canvas')
                canvas.width = width
                canvas.height = height
                const context = canvas.getContext('2d')
                context.drawImage(image, 0, 0)

                return context.getImageData(0, 0, width, height).data
            }

            const dataA = pixels(imageA)
            const dataB = pixels(imageB)
            let differing = 0

            for (let index = 0; index < dataA.length; index += 4) {
                const delta =
                    Math.abs(dataA[index] - dataB[index]) +
                    Math.abs(dataA[index + 1] - dataB[index + 1]) +
                    Math.abs(dataA[index + 2] - dataB[index + 2])

                if (delta > threshold) {
                    differing += 1
                }
            }

            return differing / (width * height)
        },
        {
            a: `data:image/png;base64,${bufferA.toString('base64')}`,
            b: `data:image/png;base64,${bufferB.toString('base64')}`,
            threshold: pixelChannelThreshold,
            band: bandHeight,
        },
    )
}
async function expectNoHorizontalOverflow(page, mode) {
    const overflows = await page.evaluate(
        () => document.documentElement.scrollWidth > window.innerWidth + 1,
    )
    expect(overflows, `${mode} render must not overflow horizontally`).toBe(
        false,
    )
}

async function captureCriticalOnly(page, viewport) {
    let abortedStylesheetCount = 0

    await page.route('**/*', async (route) => {
        if (route.request().resourceType() === 'stylesheet') {
            abortedStylesheetCount += 1
            await route.abort()

            return
        }

        await route.continue()
    })

    await page.goto(homepageUrl, { waitUntil: 'domcontentloaded' })
    await settleAfterLoad(page)
    await page.addStyleTag({ content: disableAnimationsCss })

    const hasCriticalCss = await page.evaluate(
        () => !!document.querySelector('style[data-critical-css]'),
    )
    expect(
        hasCriticalCss,
        'critical-only render must include the inline critical CSS',
    ).toBe(true)

    await expectNoHorizontalOverflow(page, 'critical-only')

    const signature = await captureLayoutSignature(page)
    const brand = await captureBrandSignals(page)
    await revealAllContent(page)
    const screenshot = await page.screenshot({
        clip: { x: 0, y: 0, width: viewport.width, height: viewport.height },
    })

    return { signature, brand, screenshot, abortedStylesheetCount }
}

async function captureFull(page, viewport) {
    await page.goto(homepageUrl, { waitUntil: 'domcontentloaded' })
    await settleAfterLoad(page)
    await page.addStyleTag({ content: disableAnimationsCss })

    const hasCriticalCss = await page.evaluate(
        () => !!document.querySelector('style[data-critical-css]'),
    )
    expect(
        hasCriticalCss,
        'full render must include the inline critical CSS',
    ).toBe(true)

    const stylesheetLinkCount = await page
        .locator('link[rel="stylesheet"]')
        .count()
    expect(
        stylesheetLinkCount,
        'full render must load at least one deferred stylesheet',
    ).toBeGreaterThanOrEqual(1)

    await expectNoHorizontalOverflow(page, 'full')

    const signature = await captureLayoutSignature(page)
    const brand = await captureBrandSignals(page)
    await revealAllContent(page)
    const screenshot = await page.screenshot({
        clip: { x: 0, y: 0, width: viewport.width, height: viewport.height },
    })

    return { signature, brand, screenshot }
}

test.describe('homepage critical CSS layout parity', () => {
    for (const { name, viewport } of parityViewports) {
        test(`above-fold layout holds without deferred CSS on ${name}`, async ({
            browser,
        }) => {
            const criticalContext = await browser.newContext({ viewport })
            const criticalPage = await criticalContext.newPage()
            await skipWhenUnreachable(criticalPage)

            const {
                signature: criticalSignature,
                brand: criticalBrand,
                screenshot: criticalShot,
                abortedStylesheetCount,
            } = await captureCriticalOnly(criticalPage, viewport)

            expect(
                abortedStylesheetCount,
                'a deferred stylesheet must have existed and been blocked',
            ).toBeGreaterThanOrEqual(1)

            await criticalContext.close()

            const fullContext = await browser.newContext({ viewport })
            const fullPage = await fullContext.newPage()
            const {
                signature: fullSignature,
                brand: fullBrand,
                screenshot: fullShot,
            } = await captureFull(fullPage, viewport)

            await fullContext.close()

            // Align the two signatures by stable DOM identity (domIndex), not
            // by slice position. The generous fold margin already keeps the two
            // sets in lockstep; this intersection is belt-and-braces so a
            // single element straddling the fold boundary can never fail the
            // structural gates for a font-only reason.
            const fullByDomIndex = new Map(
                fullSignature.map((entry) => [entry.domIndex, entry]),
            )
            const pairs = criticalSignature
                .map((critical) => ({
                    critical,
                    full: fullByDomIndex.get(critical.domIndex),
                }))
                .filter((pair) => pair.full !== undefined)

            // Gate 1: same structural elements present. The intersection must
            // be non-empty and cover (near) all of the captured elements, and
            // the shared tag sequence must match exactly — proving critical-only
            // did not drop, reorder, or duplicate structural elements.
            expect(
                pairs.length,
                `critical-only and full must share above-fold structural elements on ${name}`,
            ).toBeGreaterThan(0)
            expect(
                pairs.length,
                `critical-only and full element sets must align on ${name} (critical=${criticalSignature.length} full=${fullSignature.length} shared=${pairs.length})`,
            ).toBeGreaterThanOrEqual(
                Math.min(criticalSignature.length, fullSignature.length) - 1,
            )
            expect(
                pairs.map((pair) => pair.critical.tag),
                `critical-only must not drop/duplicate structural elements on ${name}`,
            ).toEqual(pairs.map((pair) => pair.full.tag))

            // Gate 2: horizontal geometry matches within tolerance — the core
            // "no layout collapse" guarantee. Asserted for the full-bleed page
            // landmarks (identified from the FULL render), whose `left` and
            // `width` are font-independent. Shrink-to-fit / nested / centered
            // boxes are covered by the structural gate above, not here (their
            // geometry is font-confounded). Vertical geometry (top/height) is
            // intentionally never asserted.
            const fullBleedPairs = pairs.filter(({ full }) =>
                isFullBleed(full, viewport.width),
            )

            expect(
                fullBleedPairs.length,
                `at least one full-bleed page landmark must be present above the fold on ${name}`,
            ).toBeGreaterThan(0)

            fullBleedPairs.forEach(({ critical, full }) => {
                const where = `${critical.tag}#${critical.domIndex} on ${name}`

                expect(
                    Math.abs(critical.x - full.x),
                    `left of ${where} must match (critical=${critical.x} full=${full.x})`,
                ).toBeLessThanOrEqual(horizontalTolerancePx)

                expect(
                    Math.abs(critical.width - full.width),
                    `width of ${where} must match (critical=${critical.width} full=${full.width})`,
                ).toBeLessThanOrEqual(horizontalTolerancePx)
            })

            // Gate 3: logo geometry parity. The header logo is an SVG sized only
            // by a critical-CSS rule; if that rule is dropped the SVG collapses
            // to its ~16px intrinsic fallback. A full-frame pixel % would never
            // catch this (the logo is <0.3% of the frame), so assert it directly:
            // present in both modes, not collapsed, and matching the full render.
            expect(
                criticalBrand.logo,
                `header logo must be present in critical-only on ${name}`,
            ).not.toBeNull()
            expect(
                fullBrand.logo,
                `header logo must be present in full on ${name}`,
            ).not.toBeNull()
            expect(
                criticalBrand.logo.height,
                `critical-only logo must not collapse to its fallback size on ${name} (height=${criticalBrand.logo.height})`,
            ).toBeGreaterThanOrEqual(minLogoHeightPx)
            expect(
                Math.abs(criticalBrand.logo.height - fullBrand.logo.height),
                `logo height must match full render on ${name} (critical=${criticalBrand.logo.height} full=${fullBrand.logo.height})`,
            ).toBeLessThanOrEqual(logoGeometryTolerancePx)
            expect(
                Math.abs(criticalBrand.logo.width - fullBrand.logo.width),
                `logo width must match full render on ${name} (critical=${criticalBrand.logo.width} full=${fullBrand.logo.width})`,
            ).toBeLessThanOrEqual(logoGeometryTolerancePx)

            // Gate 4: brand-paint DOM parity. The brand @font-face + colour
            // tokens are inlined into the critical CSS, so the critical-only
            // render must resolve the SAME font-family and colour as the full
            // render — i.e. no system-font fallback, no lost brand colour. This
            // asserts the original FOUC concern at the DOM level (no pixel noise).
            expect(
                criticalBrand.heading,
                `hero H1 must be present in critical-only on ${name}`,
            ).not.toBeNull()
            expect(
                criticalBrand.heading.fontFamily,
                `hero H1 must paint with the brand font in critical-only on ${name}`,
            ).toBe(fullBrand.heading.fontFamily)
            expect(
                criticalBrand.heading.color,
                `hero H1 must paint with the brand colour in critical-only on ${name}`,
            ).toBe(fullBrand.heading.color)
            expect(
                criticalBrand.logo.color,
                `header logo must paint with the brand colour in critical-only on ${name}`,
            ).toBe(fullBrand.logo.color)

            // Gate 5: tolerant pixel parity over the header band. This is the
            // true above-fold paint-parity assertion: the always-present brand
            // region (logo + nav + brand colours) must look the same with and
            // without the deferred stylesheet, within a small tolerance. The hero
            // band below intentionally defers its decorative gradient for the LCP
            // budget, so only the header band is asserted for pixels.
            const comparatorContext = await browser.newContext({ viewport })
            const comparatorPage = await comparatorContext.newPage()
            const headerDiff = await headerBandDiffRatio(
                comparatorPage,
                criticalShot,
                fullShot,
                headerBandPx,
            )
            await comparatorContext.close()

            expect(
                headerDiff,
                `header band must achieve paint parity on ${name} (diff=${(headerDiff * 100).toFixed(2)}% tolerance=${(headerBandDiffRatioTolerance * 100).toFixed(2)}%)`,
            ).toBeLessThanOrEqual(headerBandDiffRatioTolerance)
        })
    }
})
