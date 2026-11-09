// Words on the loading screen while the app starts
(function () {
  var lines = ['Counting the shillings…', 'Balancing the books…', 'Waking up the ledger…', 'Stacking your savings…', 'Almost there…']
  var el = document.getElementById('splash-text')
  var i = 0
  var timer = setInterval(function () {
    if (!document.getElementById('splash')) return clearInterval(timer)
    i = (i + 1) % lines.length
    el.textContent = lines[i]
  }, 750)
})()
