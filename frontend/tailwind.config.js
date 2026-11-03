import colors from 'tailwindcss/colors'

/**
 * SACCO's own look - deliberately not Tusome's green:
 * - brand: the SACCO's colour (deep navy by default), set at runtime from its brand_color
 * - navy: the sidebar, fixed whatever the brand colour
 * - saffron: the accent - active items, highlights, things waiting for you
 * - gray is the warm "stone" scale and paper the warm page background, so it feels like paper
 *   and ledgers rather than a cold dashboard
 */
/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,ts}'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['"Plus Jakarta Sans"', 'system-ui', 'sans-serif'],
      },
      colors: {
        gray: colors.stone,
        brand: 'rgb(var(--brand) / <alpha-value>)',
        navy: {
          50: '#eef3fa',
          100: '#dbe5f3',
          200: '#b7c9e6',
          600: '#2c5aa0',
          700: '#21477f',
          800: '#183564',
          900: '#10244a',
          950: '#0b1a33',
        },
        saffron: {
          50: '#fdf7e7',
          100: '#faebc2',
          300: '#f0c766',
          400: '#e8b33a',
          500: '#d99a1b',
          600: '#c0851a',
          700: '#8f600c',
        },
        paper: '#f5f3ee',
        ink: '#151821',
        line: '#e7e3da',
        // Charts: validated for colour-blind separation and contrast on white
        chart: {
          primary: '#2c5aa0',
          accent: '#c0851a',
        },
      },
      boxShadow: {
        card: '0 1px 2px rgba(21, 24, 33, 0.04), 0 1px 1px rgba(21, 24, 33, 0.02)',
        lift: '0 10px 30px -12px rgba(16, 36, 74, 0.25)',
      },
    },
  },
  plugins: [],
}
