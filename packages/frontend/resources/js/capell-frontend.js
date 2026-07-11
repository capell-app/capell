import focus from '@alpinejs/focus'
import intersect from '@alpinejs/intersect'
import AlpineFloatingUI from '@awcodes/alpine-floating-ui'
import Alpine from 'alpinejs'
import './widget-runtime'

window.Alpine ??= Alpine

document.addEventListener('alpine:init', () => {
    window.Alpine.plugin(focus)
    window.Alpine.plugin(intersect)
    window.Alpine.plugin(AlpineFloatingUI)
})

const startAlpine = () => {
    if (window.__capellAlpineStarted) {
        return
    }

    if (window.Alpine !== Alpine) {
        window.__capellAlpineStarted = true

        return
    }

    window.__capellAlpineStarted = true
    window.Alpine.start()
}

if (document.readyState === 'complete') {
    startAlpine()
} else {
    window.addEventListener('load', startAlpine, { once: true })
}
