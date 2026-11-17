// Words on the loading screen while the app starts
(function () {
  // The SACCO's logo, remembered from the last visit (src/stores/auth.ts)
  try {
    var logo = localStorage.getItem('sacco-logo')
    var img = document.getElementById('splash-logo')
    if (logo && img) {
      var base = document.querySelector('script[src*="brand/splash.js"]').src.replace(/brand\/splash\.js.*$/, '')
      img.src = /^(https?:)?\/\//.test(logo) || logo.charAt(0) === '/' ? logo : base + logo
      img.hidden = false
    }
  } catch (e) {}
  var lines = ['Counting the shillings…', 'Balancing the books…', 'Waking up the ledger…', 'Stacking your savings…', 'Almost there…']
  var el = document.getElementById('splash-text')
  var i = 0
  var timer = setInterval(function () {
    if (!document.getElementById('splash')) return clearInterval(timer)
    i = (i + 1) % lines.length
    el.textContent = lines[i]
  }, 750)
})()
