import forms from '@tailwindcss/forms'
import typography from '@tailwindcss/typography'

/** @type {import('tailwindcss').Config} */
module.exports = {
    darkMode: 'class',
    content: ['./resources/views/**/*.blade.php'],
    safelist: ['sortable-ghost'],
    plugins: [forms, typography],
}
