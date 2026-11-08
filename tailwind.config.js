/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './resources/**/*.twig',
        './themes/**/*.twig',
        './resources/js/**/*.js',
        './resources/css/**/*.css',
        // PHP-emitted class attributes (ShortcodeProcessor forms, newsletter
        // form, payment wizards…) are invisible to the scanner otherwise —
        // e.g. the newsletter math-captcha input's sm:w-24 never got built.
        './src/**/*.php',
        './app/**/*.php',
    ],
    safelist: [
        { pattern: /^bg-(slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-(50|100|200|300|400|500|600|700|800|900|950)$/ },
        { pattern: /^from-(slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-(50|100|200|300|400|500|600|700|800|900|950)$/ },
        { pattern: /^to-(slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-(50|100|200|300|400|500|600|700|800|900|950)$/ },
        'bg-linear-to-br',
        'bg-linear-to-r',
        'bg-linear-to-l',
        'bg-linear-to-t',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
                display: ['Playfair Display', 'Georgia', 'serif'],
                roboto: ['Roboto', 'sans-serif'],
                'open-sans': ['Open Sans', 'sans-serif'],
                montserrat: ['Montserrat', 'sans-serif'],
            },
        },
    },
    plugins: [
        require('@tailwindcss/forms'),
        require('@tailwindcss/typography'),
    ],
}
