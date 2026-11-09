// The theme before anything draws (see src/stores/theme.ts), so dark mode never flashes white
try {
  var t = localStorage.getItem('sacco-theme')
  if (t === 'dark' || (t !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.classList.add('dark')
  }
} catch (e) {}
