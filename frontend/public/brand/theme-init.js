// The theme before anything draws (see src/stores/theme.ts), so dark mode never flashes white.
// Light unless this device chose dark.
try {
  if (localStorage.getItem('sacco-theme') === 'dark') {
    document.documentElement.classList.add('dark')
  }
} catch (e) {}
