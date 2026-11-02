/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,ts}'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
      },
      colors: {
        // Each SACCO's own colour, set at runtime from its brand_color (see stores/auth.ts)
        brand: 'rgb(var(--brand) / <alpha-value>)',
      },
    },
  },
  plugins: [],
}
