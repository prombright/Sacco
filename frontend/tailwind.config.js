/**
 * SACCO's own look, taken from SMACON's banner - deliberately not Tusome's green:
 * - brand: the SACCO's colour (SMACON royal blue by default), set at runtime from its brand_color
 * - navy: the blue scale of the sidebar and highlights, fixed whatever the brand colour
 * - saffron: the banner's amber - active items, highlights, things waiting for you
 *
 * Light and dark mode: the greys, page background (paper), cards (surface), text (ink) and
 * borders (line) are CSS variables (src/style.css) that change under `.dark`, so every page
 * follows the theme without dark: variants everywhere. `white` stays white (text on navy).
 */
const v = (name) => `rgb(var(--${name}) / <alpha-value>)`

/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,ts}'],
  darkMode: 'class',
  theme: {
    extend: {
      fontFamily: {
        sans: ['"Space Grotesk Variable"', '"Inter Variable"', 'Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
      },
      colors: {
        gray: Object.fromEntries([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950].map((n) => [n, v(`g-${n}`)])),
        brand: v('brand'),
        paper: v('paper'),
        surface: v('surface'),
        ink: v('ink'),
        line: v('line'),
        // The app's violet (was SMACON royal blue), from light tints to the deep sidebar indigo
        navy: {
          50: '#f2ecff',
          100: '#e8defe',
          200: '#d4c2fd',
          300: '#b48ffb',
          600: '#6a1ff5',
          700: '#5a1fd0',
          800: '#4524b8',
          900: '#2c1780',
          950: '#1b1150',
        },
        // The amber of SMACON's banner and app tiles: highlights and things waiting for you
        saffron: {
          50: '#fef7e4',
          100: '#fdebbb',
          300: '#f7cd63',
          400: '#f2b934',
          500: '#eea90f',
          600: '#c98a08',
          700: '#8d6106',
        },
        // Charts: validated for colour-blind separation and contrast (light; dark uses brighter blue)
        chart: {
          primary: '#6a1ff5',
          accent: '#c98a08',
        },
      },
      boxShadow: {
        card: 'inset 0 1px 0 rgba(255, 255, 255, 0.7), 0 1px 2px rgba(21, 24, 33, 0.05), 0 10px 28px -18px rgba(16, 36, 74, 0.28)',
        lift: '0 2px 4px rgba(21, 24, 33, 0.04), 0 18px 40px -16px rgba(16, 36, 74, 0.32)',
      },
    },
  },
  plugins: [],
}
