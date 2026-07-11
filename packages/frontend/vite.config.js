import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig(async () => {
    return {
        plugins: [
            laravel({
                input: [
                    'resources/css/capell-frontend.css',
                    'resources/js/capell-frontend.js',
                ],
                publicDirectory: 'publishes',
                refresh: false,
            }),
            tailwindcss(),
        ],
        server: {
            open: false,
        },
        build: {
            outDir: './publishes/build',
            rollupOptions: {
                output: {
                    assetFileNames: (assetInfo) => {
                        if (assetInfo.names?.includes('capell-frontend.css')) {
                            return 'capell-frontend.css'
                        }

                        return 'assets/[name]-[hash][extname]'
                    },
                },
            },
        },
    }
})
